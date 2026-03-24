# AI Content Generator

Module Drupal custom pour générer du contenu de démo réaliste avec IA.

## Ce que fait le module

- Génère des contenus Node en lot via Drush.
- Remplit un champ texte automatiquement avec du HTML généré par IA.
- Peut ajouter des tags automatiquement.
- Peut télécharger une image aléatoire et la lier au contenu.
- Récupère les contraintes de format de texte (balises autorisées, capacités éditeur) pour guider le prompt.
- Récupère les dimensions d’image depuis le style d’affichage si largeur/hauteur ne sont pas forcées.

## Architecture (vue rapide)

```mermaid
flowchart TD
  A[Drush: ai-content-generator:bulk] --> B[State runtime\ncount, bundle, fields, images]
  B --> C[Plugin Generated Content\nNodeBundle.generate()]
  C --> D[Creation Node\nbundle cible]
  C --> E[Image optionnelle\nstyle image -> dimensions -> Picsum]
  C --> F[Tags optionnels\nchamp tags cible]
  D --> G[hook_node_presave\nai_content_generator.module]
  G --> H[ContentGenerator service]
  H --> I[resolveWritableTextField]
  H --> J[resolveTextFormat]
  H --> K[buildPrompt\n(tags autorises + styles editor)]
  K --> L[AI provider chat]
  L --> M[sanitizeGeneratedHtml]
  H --> N[setGeneratedText\nfield texte cible]
  N --> O[Node save]
  E --> O
  F --> O
```

## Installation rapide

1. Installer les dépendances du projet Drupal.
2. Activer les modules requis (`ai`, `ai_provider_mistral`, `generated_content`, etc.).
3. Activer ce module :

```bash
ddev drush en ai_content_generator -y
ddev drush cr
```

4. Fournir la clé API Mistral par variable d’environnement :

```bash
export MISTRAL_API_KEY="..."
```

Le module ne versionne pas de secret. Les configs liées à AI sont fournies en `config/optional`.

## Configuration par défaut du module

Config installée dans `ai_content_generator.settings` :

- `enabled_bundles`: bundles autorisés pour génération automatique à la création.
- `text_fields`: champs texte candidats (ordre de priorité).
- `default_bundle`: bundle par défaut pour la commande bulk.
- `image_field`: champ image par défaut.
- `tags_field`: champ tags par défaut.

## Utilisation

### Cas simple

Générer 20 contenus sur le bundle par défaut :

```bash
ddev drush ai-content-generator:bulk 20
```

### Changer le bundle

```bash
ddev drush ai-content-generator:bulk 20 --bundle=page
```

### Forcer les champs texte / tags

```bash
ddev drush ai-content-generator:bulk 20 --bundle=article --text-fields=body,field_intro --tags-field=field_tags
```

### Générer avec image

```bash
ddev drush ai-content-generator:bulk 20 --with-images --image-field=field_image
```

### Forcer les dimensions d’image

```bash
ddev drush ai-content-generator:bulk 20 --with-images --width=1200 --height=800
```

Si `--width` et `--height` ne sont pas fournis, le module essaie d’utiliser le style d’image de l’affichage (`default`, puis `teaser`).

## Vérifier que tout fonctionne

Après une génération, vérifier les logs :

```bash
ddev drush ws --count=50
```

Entrées utiles :

- contraintes format (`tags_mode`, `tags`, `styles_css`, `editor_features`),
- extrait de prompt envoyé,
- style d’image détecté et dimensions retenues.

## Limites connues

- Conçu pour test/démo, pas pour un workflow éditorial final.
- Qualité dépend du provider/modèle IA.
- Les images viennent d’un service externe (Picsum).
- Le provider Mistral peut afficher des warnings `Deprecated` non bloquants selon la version.
