# AI Content Generator

Module Drupal custom pour generer du contenu de demo "realiste" avec IA, en masse.

## Ce que fait le module

- Cree des articles automatiquement en lot.
- Remplit le champ `body` avec du contenu genere par IA (HTML propre pour Drupal).
- Ajoute des tags automatiquement sur les articles.
- Peut telecharger une image aleatoire et la lier a l'article.
- S'appuie sur le module **Generated Content** pour la structure de generation.

En pratique:

- **Generated Content** cree les noeuds.
- **AI Content Generator** orchestre le texte IA, les tags et les images.

## Pour qui

Ce module est utile pour:

- monter rapidement un site de test avec du contenu credible,
- faire des demos,
- tester des vues, listings, filtres, pages article, etc.

## Prerequis

- Module AI configure avec un provider fonctionnel (ex: Mistral).
- Module Generated Content actif.
- Type de contenu `article` present.
- Cle API Mistral fournie via variable d'environnement `MISTRAL_API_KEY`.

## Commande principale

Generer 20 articles:

```bash
ddev drush ai-content-generator:bulk 20
```

Generer 20 articles avec images:

```bash
ddev drush ai-content-generator:bulk 20 --with-images
```

Personnaliser le champ image et la taille:

```bash
ddev drush ai-content-generator:bulk 20 --with-images --image-field=field_image --width=1200 --height=800
```

Sans `--width/--height`, le module tente de recuperer les dimensions depuis le
style d'image configure sur l'affichage du contenu.

## Ce qui est genere

Pour chaque article:

- un titre,
- un body en HTML (genere par IA),
- des tags,
- une image (si activee et champ disponible).

## Notes importantes

- Ce module est pense pour du **test / demo**.
- La qualite du texte depend du provider IA et du modele configure.
- Les images sont recuperees depuis un service externe (Picsum).
- Les configs AI exportees sont dans `config/optional` (aucun secret versionne).

## Resume

Ce module te donne un pipeline simple:

1. generation en lot,
2. texte IA exploitable,
3. enrichissement automatique (tags + image).
