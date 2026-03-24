<?php

declare(strict_types=1);

namespace Drupal\ai_content_generator\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Génère du contenu HTML via le provider IA configuré.
 */
final class ContentGenerator {

  public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Génère un corps de contenu à partir d'un titre.
   */
  public function generateFromTitle(string $title, ?string $textFormat = NULL): string {
    $title = trim($title);
    if ($title === '') {
      return '';
    }

    $textFormat = $textFormat ?: $this->resolveTextFormat();

    $defaultProvider = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
    if (empty($defaultProvider['provider_id']) || empty($defaultProvider['model_id'])) {
      $this->loggerFactory->get('ai_content_generator')
        ->warning('Aucun provider IA par défaut configuré pour l’opération "chat".');
      return '';
    }

    try {
      $provider = $this->aiProviderManager->createInstance($defaultProvider['provider_id']);
      $prompt = $this->buildPrompt($title, $textFormat);
      $this->loggerFactory->get('ai_content_generator')->notice(
        'Prompt final envoye au provider (excerpt): @prompt',
        ['@prompt' => $this->truncateForLog($prompt, 1400)]
      );

      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);
      $messages->setSystemPrompt('Tu es un assistant éditorial Drupal. Reponds uniquement avec le contenu HTML du body (pas de document complet), sans Markdown et sans blocs ```.');

      /** @var \Drupal\ai\OperationType\Chat\ChatOutput $response */
      $response = $provider->chat($messages, $defaultProvider['model_id'], ['ai_content_generator']);
      $message = $response->getNormalized();
      return $this->sanitizeGeneratedHtml((string) $message->getText());
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('ai_content_generator')->error(
        'Échec de génération IA: @message',
        ['@message' => $exception->getMessage()]
      );
      return '';
    }
  }

  /**
   * Retourne un format texte cohérent pour le HTML généré.
   */
  public function resolveTextFormat(): string {
    if (FilterFormat::load('full_html')) {
      return 'full_html';
    }

    if (FilterFormat::load('basic_html')) {
      return 'basic_html';
    }

    return 'plain_text';
  }

  /**
   * Resolve the first writable text field on a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being generated.
   * @param string[] $preferredFields
   *   Preferred field machine names, in order.
   *
   * @return string|null
   *   Writable empty text field machine name or NULL.
   */
  public function resolveWritableTextField(NodeInterface $node, array $preferredFields = []): ?string {
    $supported_types = ['text', 'text_long', 'text_with_summary', 'string_long', 'string'];

    $candidates = [];
    if (!empty($preferredFields)) {
      $candidates = $preferredFields;
    }
    else {
      foreach ($node->getFieldDefinitions() as $field_name => $definition) {
        if (in_array($definition->getType(), $supported_types, TRUE)) {
          $candidates[] = $field_name;
        }
      }
    }

    foreach ($candidates as $field_name) {
      if (!$node->hasField($field_name) || !$node->get($field_name)->isEmpty()) {
        continue;
      }

      $definition = $node->getFieldDefinition($field_name);
      if (in_array($definition->getType(), $supported_types, TRUE)) {
        return $field_name;
      }
    }

    return NULL;
  }

  /**
   * Apply generated text into a node field.
   */
  public function setGeneratedText(NodeInterface $node, string $fieldName, string $content, string $textFormat): void {
    $definition = $node->getFieldDefinition($fieldName);
    $field_type = $definition->getType();

    if (in_array($field_type, ['text', 'text_long', 'text_with_summary'], TRUE)) {
      $node->set($fieldName, [
        'value' => $content,
        'format' => $textFormat,
      ]);
      return;
    }

    // Fallback for plain string fields: keep text-only content.
    $plain_text = trim(strip_tags($content));
    $node->set($fieldName, $plain_text);
  }

  /**
   * Construit le prompt de génération.
   */
  private function buildPrompt(string $title, string $textFormat): string {
    $formatConstraints = $this->buildTextFormatConstraints($textFormat);

    return <<<PROMPT
Rédige un contenu professionnel en HTML.
Contraintes :
- Utilise les balises <h2> et <h3>.
- Utilise des paragraphes <p>.
- Ajoute au moins une liste <ul><li>.
- Ajoute un tableau HTML avec <table>, <thead>, <tbody>, <tr>, <th>, <td>.
- Le tableau doit contenir au moins 3 lignes de donnees.
- Ton neutre et informatif.
- 350 à 600 mots.
- N'utilise pas Markdown.
- Ne renvoie pas de bloc ```html.
- Ne renvoie pas de balises <html>, <head>, <meta>, <title> ni <body>.
$formatConstraints
Sujet : {$title}
PROMPT;
  }

  /**
   * Construit des contraintes prompt depuis le format de texte Drupal.
   */
  private function buildTextFormatConstraints(string $textFormat): string {
    $constraints = [];
    $allowedTags = [];
    $tagsMode = 'unknown';

    $format = FilterFormat::load($textFormat);
    if ($format) {
      $tagsInfo = $this->extractAllowedTagsInfo($format);
      $allowedTags = $tagsInfo['tags'];
      $tagsMode = $tagsInfo['mode'];

      if ($tagsMode === 'unrestricted') {
        $constraints[] = '- Le format de texte est non restreint: tu peux utiliser un HTML editorial standard (sans balises de document complet).';
      }
      elseif (!empty($allowedTags)) {
        $constraints[] = '- Balises autorisees pour ce format: ' . implode(', ', array_map(static fn(string $tag): string => "<$tag>", $allowedTags)) . '.';
      }
    }

    $editorStyles = $this->extractEditorStyles($textFormat);
    $editorFeatures = $this->extractEditorFeatures($textFormat);

    if (!empty($editorStyles)) {
      $constraints[] = '- Styles CSS disponibles dans l editeur: ' . implode('; ', $editorStyles) . '.';
      $constraints[] = '- N utilise pas de classes CSS non listees ci-dessus.';
    }
    elseif ($this->hasEditor($textFormat)) {
      $constraints[] = '- Aucun style CSS personnalise configure dans l editeur.';
    }

    if (!empty($editorFeatures)) {
      $constraints[] = '- Capacites editoriales disponibles: ' . implode('; ', $editorFeatures) . '.';
    }

    $this->loggerFactory->get('ai_content_generator')->notice(
      'Contraintes format "@format": tags_mode=@tags_mode | tags=@tags | styles_css=@styles | editor_features=@features',
      [
        '@format' => $textFormat,
        '@tags_mode' => $tagsMode,
        '@tags' => $this->toLogList($allowedTags),
        '@styles' => $this->toLogList($editorStyles),
        '@features' => $this->toLogList($editorFeatures),
      ]
    );

    return implode("\n", $constraints);
  }

  /**
   * Extrait les balises HTML autorisees via filter_html.
   *
   * @return string[]
   *   Liste des tags autorises.
   */
  private function extractAllowedTagsInfo(FilterFormat $format): array {
    $filters = $format->filters();
    $htmlFilter = $filters->get('filter_html');
    if (!$htmlFilter || !$htmlFilter->status) {
      return [
        'mode' => 'unrestricted',
        'tags' => [],
      ];
    }

    $settings = (array) ($htmlFilter->settings ?? []);
    $allowedHtml = (string) ($settings['allowed_html'] ?? '');
    if ($allowedHtml === '') {
      return [
        'mode' => 'restricted',
        'tags' => [],
      ];
    }

    preg_match_all('/<\s*([a-z0-9-]+)/i', $allowedHtml, $matches);
    $tags = array_map('strtolower', $matches[1] ?? []);
    $tags = array_values(array_unique($tags));
    sort($tags);

    return [
      'mode' => 'restricted',
      'tags' => $tags,
    ];
  }

  /**
   * Extrait les styles disponibles depuis la config CKEditor du format.
   *
   * @return string[]
   *   Liste de styles lisibles pour le prompt.
   */
  private function extractEditorStyles(string $textFormat): array {
    if (!class_exists(Editor::class)) {
      return [];
    }

    $editor = Editor::load($textFormat);
    if (!$editor) {
      return [];
    }

    $settings = (array) $editor->getSettings();
    $styleItems = (array) ($settings['plugins']['ckeditor5_style']['styles'] ?? []);
    if (empty($styleItems)) {
      return [];
    }

    $styles = [];
    foreach ($styleItems as $item) {
      if (!is_array($item)) {
        continue;
      }
      $label = trim((string) ($item['label'] ?? $item['name'] ?? ''));
      $element = trim((string) ($item['element'] ?? ''));
      $classes = $item['classes'] ?? [];
      if (is_array($classes)) {
        $classes = array_filter(array_map('trim', $classes));
      }
      elseif (is_string($classes)) {
        $classes = array_filter(array_map('trim', explode(' ', $classes)));
      }
      else {
        $classes = [];
      }

      $parts = [];
      if ($label !== '') {
        $parts[] = $label;
      }
      if ($element !== '') {
        $parts[] = "element {$element}";
      }
      if (!empty($classes)) {
        $parts[] = 'classes ' . implode(', ', $classes);
      }

      $line = trim(implode(' - ', $parts));
      if ($line !== '') {
        $styles[] = $line;
      }
    }

    return array_values(array_unique($styles));
  }

  /**
   * Extrait des capacites d'edition utiles pour le prompt.
   *
   * @return string[]
   *   Liste de capacites.
   */
  private function extractEditorFeatures(string $textFormat): array {
    if (!class_exists(Editor::class)) {
      return [];
    }
    $editor = Editor::load($textFormat);
    if (!$editor) {
      return [];
    }

    $settings = (array) $editor->getSettings();
    $features = [];

    $enabledHeadings = (array) ($settings['plugins']['ckeditor5_heading']['enabled_headings'] ?? []);
    if (!empty($enabledHeadings)) {
      $mapped = array_map(
        static fn(string $value): string => str_starts_with($value, 'heading') ? 'h' . substr($value, 7) : $value,
        $enabledHeadings
      );
      $features[] = 'headings ' . implode(', ', $mapped);
    }

    $listProperties = (array) ($settings['plugins']['ckeditor5_list']['properties'] ?? []);
    if (!empty($listProperties['styles'])) {
      $features[] = 'list styles';
    }
    if (!empty($listProperties['startIndex'])) {
      $features[] = 'ordered list start index';
    }
    if (!empty($listProperties['reversed'])) {
      $features[] = 'ordered list reversed';
    }

    $toolbarItems = (array) ($settings['toolbar']['items'] ?? []);
    if (!empty($toolbarItems)) {
      $toolbarItems = array_values(array_filter(array_map('strval', $toolbarItems), static fn(string $item): bool => $item !== '|' && $item !== ''));
      if (!empty($toolbarItems)) {
        $features[] = 'toolbar ' . implode(', ', array_slice($toolbarItems, 0, 20));
      }
    }

    return array_values(array_unique($features));
  }

  /**
   * Indique si un editeur est configure sur ce format.
   */
  private function hasEditor(string $textFormat): bool {
    if (!class_exists(Editor::class)) {
      return FALSE;
    }
    return (bool) Editor::load($textFormat);
  }

  /**
   * Transforme une liste en texte court pour les logs.
   */
  private function toLogList(array $values): string {
    if (empty($values)) {
      return '(none)';
    }

    $line = implode(' | ', $values);
    if (strlen($line) > 400) {
      return substr($line, 0, 400) . '...';
    }

    return $line;
  }

  /**
   * Tronque une chaine pour log sans perdre le sens.
   */
  private function truncateForLog(string $value, int $maxLength): string {
    $value = str_replace(["\r\n", "\n", "\r"], ' | ', trim($value));
    if (strlen($value) <= $maxLength) {
      return $value;
    }
    return substr($value, 0, $maxLength) . '...';
  }

  /**
   * Nettoie le HTML genere par le modele pour Drupal.
   */
  private function sanitizeGeneratedHtml(string $html): string {
    $html = trim($html);
    if ($html === '') {
      return '';
    }

    // Retire les blocs Markdown de type ```html ... ```.
    $html = preg_replace('/^\s*```[a-zA-Z0-9_-]*\s*/', '', $html) ?? $html;
    $html = preg_replace('/\s*```\s*$/', '', $html) ?? $html;
    $html = str_replace(['```html', '```HTML', '```'], '', $html);

    // Retire le doctype si present.
    $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html) ?? $html;

    // Si le modele renvoie un document complet, on extrait le contenu utile.
    if (preg_match('/<article[^>]*>(.*)<\/article>/is', $html, $matches) === 1) {
      $html = $matches[1];
    }
    elseif (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches) === 1) {
      $html = $matches[1];
    }

    // Elimine les balises de structure de document non souhaitees.
    $html = preg_replace('/<\/?(html|head|body|meta|title)[^>]*>/i', '', $html) ?? $html;

    return trim($html);
  }

}
