# Réserver des places d’atelier avec MappedTable et Symfony

Un atelier a une capacité et des réservations par groupe. Chaque réservation a pour clé
`(workshop_id, reference)`, ce qui permet de réutiliser la même référence dans un autre atelier.
Le service de réservation travaille directement avec deux `MappedRowSet` injectés par un
conteneur Symfony compilé. Il adresse les clés composites avec des tableaux associatifs. Le total des places réservées est lu par une requête PostgreSQL.
[Service de réservation](src/WorkshopRegistration.php) · [Déclaration du schéma](src/WorkshopSchema.php)

## Ce que montre l’exemple

- `WorkshopSchema` déclare les deux tables et leur relation une seule fois. Symfony découvre cette
  définition via `#[DefineSchema]` et compile les deux row sets. La même méthode fournit le schéma
  utilisé pour générer le DDL PostgreSQL.
- La publication crée l’atelier et ses réservations initiales dans le même flush. La déclaration
  du propriétaire permet à MappedTable d’insérer l’atelier avant les réservations qui le référencent.
- Pour une nouvelle réservation, le service lit et observe la capacité avec `withKey()->observe(['capacity'])`, puis observe
  la somme des places déjà réservées avec un query guard dont la valeur est l’identifiant de l’atelier.
  Les écritures de sièges sur le row set participent au guard. Deux participants qui ont lu le même
  total peuvent donc préparer des réservations distinctes. Le premier flush les confirme et le rejeu
  du second détecte que la décision repose sur un total périmé.
- Le retrait observe le membership avec `observeKeysForOwner()` et réconcilie la collection avec une liste vide. Le
  flush supprime les réservations de cet atelier avant de supprimer l’atelier lui-même.

[Sélection des row sets, déclaration du guard et services Symfony](config/services.php)

## Exécuter la démonstration

Depuis la racine du dépôt, avec PHP 8.5 et `pdo_pgsql` disponibles, démarrer PostgreSQL
et installer les dépendances de l’exemple.

```bash
docker compose up -d --wait postgres
composer install --working-dir=examples/mapped-table-symfony
export MAPPED_TABLE_DATABASE_DSN='pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test'
php examples/mapped-table-symfony/bin/demo.php
```

Le script compile le conteneur avant d’ouvrir les connexions, puis crée deux instances du même
conteneur pour simuler le site et le guichet. Le guichet confirme la dernière place pendant que la
décision du site est encore en attente de flush. Le script retire ensuite l’atelier. Chaque exécution
crée et supprime son propre schéma PostgreSQL, ce qui nécessite le droit `CREATE` sur la base.
[Compilation du conteneur](src/WorkshopContainer.php) · [Scénario exécutable](bin/demo.php)

## Tests d’intégration

```bash
composer --working-dir=examples/mapped-table-symfony test
```

La suite vérifie que le retrait préserve les réservations d’un autre atelier et que deux références
distinctes ne peuvent pas réserver la dernière place. Avec les dépendances du dépôt déjà installées,
`bin/phpunit -c examples/mapped-table-symfony/phpunit.xml.dist` exécute aussi la suite.
[Tests PostgreSQL](tests/WorkshopRegistrationTest.php) · [Contrat MappedTable](../../docs/stored-state/mapped-table.md)
