<?php

declare(strict_types=1);

namespace Drupal\ai_content_generator\Plugin\GeneratedContent;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\generated_content\Attribute\GeneratedContent;
use Drupal\generated_content\Plugin\GeneratedContent\GeneratedContentPluginBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Générateur de contenus Node pour le pipeline Generated Content + IA.
 */
#[GeneratedContent(
  id: 'ai_content_generator_node_bundle',
  entity_type: 'node',
  bundle: 'article',
  weight: 50
)]
final class NodeBundle extends GeneratedContentPluginBase {

  /**
   * {@inheritdoc}
   */
  public function generate(): array {
    $state = \Drupal::state();
    $config = \Drupal::config('ai_content_generator.settings');
    $count = max(1, (int) $state->get('ai_content_generator.generated_content.count', 10));
    $bundle = (string) $state->get('ai_content_generator.generated_content.bundle', (string) $config->get('default_bundle'));
    if ($bundle === '') {
      $bundle = 'article';
    }
    $withImages = (bool) $state->get('ai_content_generator.generated_content.with_images', TRUE);
    $imageField = (string) $state->get('ai_content_generator.generated_content.image_field', (string) $config->get('image_field'));
    if ($imageField === '') {
      $imageField = 'field_image';
    }
    $tagsField = (string) $state->get('ai_content_generator.generated_content.tags_field', (string) $config->get('tags_field'));
    if ($tagsField === '') {
      $tagsField = 'field_tags';
    }
    $width = (int) $state->get('ai_content_generator.generated_content.image_width', 0);
    $height = (int) $state->get('ai_content_generator.generated_content.image_height', 0);

    if ($width < 1 || $height < 1) {
      $dimensions = $this->resolveImageDimensionsFromDisplay($bundle, $imageField);
      $width = $dimensions['width'];
      $height = $dimensions['height'];
    }

    \Drupal::logger('ai_content_generator')->notice(
      'Generation bulk: images field="@field" dimensions=@widthx@height (with_images=@with_images).',
      [
        '@field' => $imageField,
        '@width' => (string) $width,
        '@height' => (string) $height,
        '@with_images' => $withImages ? 'true' : 'false',
      ]
    );

    $nodes = [];
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    for ($i = 1; $i <= $count; $i++) {
      $title = sprintf('Contenu IA %s #%d', date('Y-m-d H:i:s'), $i);

      /** @var \Drupal\node\NodeInterface $node */
      $node = $nodeStorage->create([
        'type' => $bundle,
        'title' => $title,
        'status' => 1,
      ]);

      if ($withImages) {
        $this->attachImageIfPossible($node, $imageField, $width, $height, $title, $i);
      }

      $this->attachTagsIfPossible($node, $tagsField, $title, $i);

      // Le hook ai_content_generator_node_presave() complète automatiquement le texte.
      $node->save();
      $nodes[] = $node;
    }

    return $nodes;
  }

  /**
   * Attache des tags au contenu si le champ existe.
   */
  private function attachTagsIfPossible(NodeInterface $node, string $tagsField, string $title, int $index): void {
    if ($tagsField === '' || !$node->hasField($tagsField)) {
      return;
    }

    $definition = $node->getFieldDefinition($tagsField);
    $targetType = (string) ($definition->getFieldStorageDefinition()->getSetting('target_type') ?? '');
    if ($targetType !== 'taxonomy_term') {
      return;
    }

    $handlerSettings = (array) $definition->getSetting('handler_settings');
    $targetBundles = (array) ($handlerSettings['target_bundles'] ?? []);
    $vid = !empty($targetBundles) ? (string) array_key_first($targetBundles) : 'tags';

    $baseTerms = [
      'Intelligence artificielle',
      'Drupal',
      'Automatisation',
      'Innovation',
      'Productivite',
      'Transformation numerique',
      'Data',
      'Technologie',
    ];

    // Stabilise un peu la variation selon l'index pour éviter des doublons parfaits.
    $selected = [
      $baseTerms[$index % count($baseTerms)],
      $baseTerms[($index + 3) % count($baseTerms)],
    ];

    $termIds = [];
    foreach ($selected as $termName) {
      $term = $this->loadOrCreateTerm($vid, $termName);
      if ($term instanceof TermInterface) {
        $termIds[] = ['target_id' => $term->id()];
      }
    }

    if (!empty($termIds)) {
      $node->set($tagsField, $termIds);
    }
  }

  /**
   * Charge ou crée un terme de taxonomie.
   */
  private function loadOrCreateTerm(string $vid, string $name): ?TermInterface {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $matches = $storage->loadByProperties([
      'vid' => $vid,
      'name' => $name,
    ]);

    if (!empty($matches)) {
      $first = reset($matches);
      return $first instanceof TermInterface ? $first : NULL;
    }

    $term = $storage->create([
      'vid' => $vid,
      'name' => $name,
    ]);
    $term->save();

    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Attache une image Picsum si un champ image compatible existe.
   */
  private function attachImageIfPossible(
    NodeInterface $node,
    string $imageField,
    int $width,
    int $height,
    string $title,
    int $index,
  ): void {
    if (!$node->hasField($imageField)) {
      return;
    }

    $file = $this->downloadRandomImage($title, $index, $width, $height);
    if (!$file) {
      return;
    }

    $definition = $node->getFieldDefinition($imageField);
    $targetType = (string) ($definition->getSetting('target_type') ?? '');

    if ($targetType === 'file') {
      $node->set($imageField, [
        'target_id' => $file->id(),
        'alt' => $title,
      ]);
      return;
    }

    // Cas standard Drupal: champ image en référence media.
    if ($targetType === 'media' && $this->entityTypeManager->hasDefinition('media')) {
      $mediaStorage = $this->entityTypeManager->getStorage('media');
      $media = $mediaStorage->create([
        'bundle' => 'image',
        'name' => $title,
        'status' => 1,
      ]);

      if ($media->hasField('field_media_image')) {
        $media->set('field_media_image', [
          'target_id' => $file->id(),
          'alt' => $title,
        ]);
        $media->save();

        $node->set($imageField, [
          'target_id' => $media->id(),
          'alt' => $title,
        ]);
      }
    }
  }

  /**
   * Résout les dimensions depuis le style d'image d'affichage.
   *
   * @return array{width:int,height:int}
   *   Dimensions retenues.
   */
  private function resolveImageDimensionsFromDisplay(string $bundle, string $imageField): array {
    $fallback = ['width' => 1200, 'height' => 800];

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $displayRepository */
    $displayRepository = \Drupal::service('entity_display.repository');
    $viewModes = ['default', 'teaser'];

    foreach ($viewModes as $viewMode) {
      $display = $displayRepository->getViewDisplay('node', $bundle, $viewMode);
      $component = $display->getComponent($imageField);
      $styleId = (string) ($component['settings']['image_style'] ?? '');
      if ($styleId === '') {
        continue;
      }

      $style = ImageStyle::load($styleId);
      if (!$style) {
        continue;
      }

      $resolved = $this->extractDimensionsFromStyle($style, $fallback['width'], $fallback['height']);
      if ($resolved['width'] > 0 && $resolved['height'] > 0) {
        \Drupal::logger('ai_content_generator')->notice(
          'Image style detecte: view_mode="@view_mode", bundle="@bundle", field="@field", style="@style", dimensions=@widthx@height.',
          [
            '@view_mode' => $viewMode,
            '@bundle' => $bundle,
            '@field' => $imageField,
            '@style' => $styleId,
            '@width' => (string) $resolved['width'],
            '@height' => (string) $resolved['height'],
          ]
        );
        return $resolved;
      }
    }

    \Drupal::logger('ai_content_generator')->notice(
      'Aucun style image exploitable trouve pour field="@field" bundle="@bundle". Fallback dimensions=@widthx@height.',
      [
        '@field' => $imageField,
        '@bundle' => $bundle,
        '@width' => (string) $fallback['width'],
        '@height' => (string) $fallback['height'],
      ]
    );

    return $fallback;
  }

  /**
   * Extrait une largeur/hauteur à partir des effets du style d'image.
   *
   * @return array{width:int,height:int}
   *   Dimensions retenues.
   */
  private function extractDimensionsFromStyle(ImageStyle $style, int $defaultWidth, int $defaultHeight): array {
    $width = 0;
    $height = 0;

    foreach ($style->getEffects() as $effect) {
      $configuration = $effect->getConfiguration();
      $data = (array) ($configuration['data'] ?? []);

      if (!empty($data['width']) && is_numeric($data['width'])) {
        $width = (int) $data['width'];
      }
      if (!empty($data['height']) && is_numeric($data['height'])) {
        $height = (int) $data['height'];
      }
    }

    if ($width < 1) {
      $width = $defaultWidth;
    }
    if ($height < 1) {
      $height = $defaultHeight;
    }

    return ['width' => $width, 'height' => $height];
  }

  /**
   * Télécharge une image random via Picsum et crée un fichier Drupal.
   */
  private function downloadRandomImage(
    string $title,
    int $index,
    int $width,
    int $height,
  ): ?FileInterface {
    $seed = rawurlencode($title . '-' . $index);
    $url = sprintf('https://picsum.photos/seed/%s/%d/%d.jpg', $seed, $width, $height);

    try {
      $response = \Drupal::httpClient()->request('GET', $url, ['timeout' => 20]);
      if ($response->getStatusCode() !== 200) {
        return NULL;
      }

      $fileData = (string) $response->getBody();
      if ($fileData === '') {
        return NULL;
      }

      $directory = 'public://generated-images';
      \Drupal::service('file_system')->prepareDirectory(
        $directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
      );

      $safeName = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $seed) ?? 'image');
      $safeName = trim($safeName, '-');
      if ($safeName === '') {
        $safeName = 'generated-image';
      }
      $destination = sprintf('%s/%s-%d.jpg', $directory, $safeName, random_int(1000, 999999));

      /** @var \Drupal\file\FileRepositoryInterface $fileRepository */
      $fileRepository = \Drupal::service('file.repository');
      return $fileRepository->writeData($fileData, $destination, FileExists::Replace);
    }
    catch (\Throwable $exception) {
      \Drupal::logger('ai_content_generator')->warning(
        'Image non générée pour "@title": @message',
        [
          '@title' => $title,
          '@message' => $exception->getMessage(),
        ]
      );
      return NULL;
    }
  }

}
