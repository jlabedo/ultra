# TODO

- [ ] Ordonner les suppressions de colonnes et de leurs index dépendants dans
  Schema. Postgres supprime l'index avec la colonne ; le `DropIndex` émis ensuite
  échoue. Couvrir l'exécution complète de cette migration, voir [Schema](docs/schema/README.md).

- [ ] Ajouter un module de retry au framework. La politique de retry reste
  actuellement à la charge de l'appelant ; définir le périmètre du module,
  notamment les conflits optimistes et son éventuelle articulation avec la
  livraison asynchrone.
  Étudier séparément les nouvelles livraisons de messages : backoff, plafond
  de tentatives et renouvellement des leases pendant les traitements longs.

- [ ] Générer le DDL d'IAM depuis Schema, notamment le `CHECK` de table sur le
  périmètre des attributions. Préserver la réinstallation sur une base déjà peuplée.
