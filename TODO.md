# TODO

- [ ] Ajouter un module de retry au framework. La politique de retry reste
  actuellement à la charge de l'appelant ; définir le périmètre du module,
  notamment les conflits optimistes et son éventuelle articulation avec la
  livraison asynchrone.
  Étudier le backoff progressif et le renouvellement automatique des leases
  pendant les traitements longs.
