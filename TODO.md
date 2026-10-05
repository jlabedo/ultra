# TODO

- [ ] Ajouter la suppression explicite dans StateStore, afin que l'appelant puisse
  supprimer un State sans accéder au MappedRowSet sous-jacent. Préserver les
  attentes optimistes et la participation aux query guards ; préciser le devenir
  des collections du State supprimé. Couvrir la suppression et les conflits avec
  des tests en mémoire et Postgres.

- [ ] Étendre les query guards déclaratifs de StateStore aux tables de
  `StateCollection`, en dérivant les scopes depuis les colonnes du propriétaire
  et en incluant ajouts, suppressions et modifications des items. L'exemple
  `examples/iam` doit protéger les décisions d'administration contre une
  révocation de permission dans la collection d'un rôle. Il utilise aujourd'hui
  `lock(TenantState)` et `observeQuery()` avec une garde coopérative par tenant,
  correcte mais sérialisant toutes ses écritures. Couvrir ce cas en Postgres,
  y compris une révocation concurrente pendant l'attente de la garde.

- [ ] Relier les métadonnées StateStore à Schema pour dériver le schéma de base
  (identifiants composites, colonnes et tables des collections), avec déclaration
  des index, contraintes uniques et références. Les exemples coworking et IAM
  maintiennent actuellement le DDL à la main, en parallèle des attributs PHP.
  Ne pas déduire automatiquement les index métier depuis les requêtes SQL.
