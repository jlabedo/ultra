# TODO

- [ ] Paginer `EventSourcedStateStore::load()` pour reconstruire les states au-delà
  des 1 000 premiers événements. Protéger la reconstruction sur plusieurs pages ;
  voir la [limite actuelle](docs/event-sourcing/states.md).

- [ ] Créer les index déclarés lors de la création d'une table dans Schema.
  `CreateTable` conserve les définitions d'index, mais son SQL les omet ; couvrir
  l'effet réel sur Postgres, voir [Schema](docs/schema/README.md).

- [ ] Ordonner les suppressions de colonnes et de leurs index dépendants dans
  Schema. Postgres supprime l'index avec la colonne ; le `DropIndex` émis ensuite
  échoue. Couvrir l'exécution complète de cette migration, voir [Schema](docs/schema/README.md).

- [ ] Ajouter un module de retry au framework. La politique de retry reste
  actuellement à la charge de l'appelant ; définir le périmètre du module,
  notamment les conflits optimistes et son éventuelle articulation avec la
  livraison asynchrone.
  Étudier séparément les nouvelles livraisons de messages : backoff, plafond
  de tentatives et renouvellement des leases pendant les traitements longs.

- [ ] Reprendre l'expérimentation de mise à jour du cache de l'exemple compliance,
  commencée sur une autre branche avec Coordination. Évaluer les primitives
  manquantes à partir de ce cas d'usage et revoir le nom du module si nécessaire.

- [ ] Ajouter la suppression explicite dans StateStore, afin que l'appelant puisse
  supprimer un State sans accéder au MappedRowSet sous-jacent. Préserver les
  attentes optimistes et la participation aux query guards ; préciser le devenir
  des collections du State supprimé. Couvrir la suppression et les conflits avec
  des tests en mémoire et Postgres.

- [ ] Relier les métadonnées StateStore à Schema pour dériver le schéma de base
  (identifiants composites, colonnes et tables des collections), avec déclaration
  des index, contraintes uniques et références. Les exemples coworking et IAM
  maintiennent actuellement le DDL à la main, en parallèle des attributs PHP.
  Ne pas déduire automatiquement les index métier depuis les requêtes SQL.
