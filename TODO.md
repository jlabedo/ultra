# TODO

- [ ] Ajouter un module de retry au framework. La politique de retry reste
  actuellement à la charge de l'appelant ; définir le périmètre du module,
  notamment les conflits optimistes et son éventuelle articulation avec la
  livraison asynchrone.
  Étudier séparément les nouvelles livraisons de messages : backoff, plafond
  de tentatives et renouvellement des leases pendant les traitements longs.
