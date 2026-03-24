<?php

declare(strict_types=1);

namespace Drupal\ai_content_generator\Commands;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\generated_content\GeneratedContentRepository;
use Drupal\generated_content\Plugin\GeneratedContent\GeneratedContentPluginManager;
use Drush\Commands\DrushCommands;

/**
 * Commandes Drush pour la génération en masse.
 */
final class AiContentGeneratorCommands extends DrushCommands {

  public function __construct(
    private readonly StateInterface $state,
    private readonly GeneratedContentPluginManager $generatedContentPluginManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct();
  }

  /**
   * Génère des contenus Node via Generated Content + IA.
   *
   * @param int $count
   *   Nombre de contenus à créer.
   * @param array $options
   *   Options de génération.
   *
   * @command ai-content-generator:bulk
   * @aliases aicg-bulk
   * @option with-images Télécharger une image Picsum par contenu (activé par défaut).
   * @option bundle Bundle node cible (défaut: config module).
   * @option text-fields Champs texte cibles, séparés par virgules (ex: body,field_intro).
   * @option image-field Machine name du champ image (défaut: field_image).
   * @option tags-field Machine name du champ tags (défaut: field_tags).
   * @option width Largeur de l'image (défaut: auto depuis le style d'affichage).
   * @option height Hauteur de l'image (défaut: auto depuis le style d'affichage).
   * @usage drush ai-content-generator:bulk 20
   * @usage drush ai-content-generator:bulk 20 --with-images --image-field=field_image
   */
  public function bulk(int $count = 10, array $options = [
    'with-images' => TRUE,
    'bundle' => '',
    'text-fields' => '',
    'image-field' => 'field_image',
    'tags-field' => 'field_tags',
    'width' => 0,
    'height' => 0,
  ]): void {
    if ($count < 1) {
      $this->logger()->error('Le nombre doit être supérieur à 0.');
      return;
    }

    $withImages = (bool) ($options['with-images'] ?? TRUE);
    $bundle = trim((string) ($options['bundle'] ?? ''));
    if ($bundle === '') {
      $bundle = (string) (\Drupal::config('ai_content_generator.settings')->get('default_bundle') ?: 'article');
    }
    $textFieldsCsv = trim((string) ($options['text-fields'] ?? ''));
    $textFields = [];
    if ($textFieldsCsv !== '') {
      $textFields = array_values(array_filter(array_map('trim', explode(',', $textFieldsCsv))));
    }
    $imageField = (string) ($options['image-field'] ?? 'field_image');
    $tagsField = (string) ($options['tags-field'] ?? 'field_tags');
    $width = max(0, (int) ($options['width'] ?? 0));
    $height = max(0, (int) ($options['height'] ?? 0));

    $this->state->set('ai_content_generator.generated_content.count', $count);
    $this->state->set('ai_content_generator.generated_content.bundle', $bundle);
    $this->state->set('ai_content_generator.generated_content.text_fields', $textFields);
    $this->state->set('ai_content_generator.generated_content.with_images', $withImages);
    $this->state->set('ai_content_generator.generated_content.image_field', $imageField);
    $this->state->set('ai_content_generator.generated_content.tags_field', $tagsField);
    $this->state->set('ai_content_generator.generated_content.image_width', $width);
    $this->state->set('ai_content_generator.generated_content.image_height', $height);

    try {
      /** @var \Drupal\generated_content\Plugin\GeneratedContent\GeneratedContentPluginInterface $plugin */
      $plugin = $this->generatedContentPluginManager->createInstance('ai_content_generator_node_bundle');
      $entities = $plugin->generate();

      $repository = GeneratedContentRepository::getInstance();
      $repository->addEntities($entities);
      $repository->clearCaches();

      $this->logger()->success(sprintf(
        '%d contenu(s) node (%s) généré(s) via Generated Content%s.',
        count($entities),
        $bundle,
        $withImages ? ' avec images' : ''
      ));
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('ai_content_generator')->error(
        'Erreur pendant la génération bulk: @message',
        ['@message' => $exception->getMessage()]
      );
      $this->logger()->error('La génération a échoué. Vérifie les logs Drupal.');
    }
    finally {
      $this->state->delete('ai_content_generator.generated_content.count');
      $this->state->delete('ai_content_generator.generated_content.bundle');
      $this->state->delete('ai_content_generator.generated_content.text_fields');
      $this->state->delete('ai_content_generator.generated_content.with_images');
      $this->state->delete('ai_content_generator.generated_content.image_field');
      $this->state->delete('ai_content_generator.generated_content.tags_field');
      $this->state->delete('ai_content_generator.generated_content.image_width');
      $this->state->delete('ai_content_generator.generated_content.image_height');
    }
  }

}
