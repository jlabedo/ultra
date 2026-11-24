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

- [ ] Étendre la génération DDL de Schema pour couvrir les contraintes et les
  index utilisés par Coworking et IAM. Ces exemples déclarent leurs row sets
  dans Schema et conservent leurs scripts DDL pour les migrations.
