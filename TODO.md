# TODO

- [ ] Ajouter la suppression explicite dans StateStore, afin que l'appelant puisse
  supprimer un State sans accéder au MappedRowSet sous-jacent. Préserver les
  attentes optimistes et la participation aux query guards ; préciser le devenir
  des collections du State supprimé. Couvrir la suppression et les conflits avec
  des tests en mémoire et Postgres.
