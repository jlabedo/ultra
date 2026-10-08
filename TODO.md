# TODO

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

- [ ] Relier les métadonnées StateStore à Schema pour dériver le schéma de base
  (identifiants composites, colonnes et tables des collections), avec déclaration
  des index, contraintes uniques et références. Les exemples coworking et IAM
  maintiennent actuellement le DDL à la main, en parallèle des attributs PHP.
  Ne pas déduire automatiquement les index métier depuis les requêtes SQL.

- [ ] Configurer par table la protection d'absence de l'adaptateur PostgreSQL
  de MappedTable, notamment pour les tables dédiées aux projections sans
  attentes d'absence. Exposer la politique via la factory, activée par défaut
  et cohérente pour tous les adaptateurs d'une même table. Sa désactivation
  doit retirer les verrous advisory sur les clés des inserts et upserts,
  préserver les contraintes SQL, les contrôles de lignes affectées et les
  autres protections, et rejeter les attentes d'absence seules (`MODE_EXPECT`
  avec `expectedPresence = absent`). Les inserts restent autorisés malgré
  leur attente d'absence implicite. Vérifier le SQL émis et la concurrence
  sur PostgreSQL ; conserver l'atomicité des écritures de projection et de
  leur checkpoint. Voir [MappedTable](docs/stored-state/mapped-table.md#postgresql-adapter)
  et [projections](docs/projections/README.md).
