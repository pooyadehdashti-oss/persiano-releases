# Batchly 0.45.6

Correction-only release for ingredient identity integrity.

- Detects strong contradictions between ingredient name, family, and aliases.
- Blocks automatic supplier-package backfill while identity is unresolved.
- Adds Ingredient Master > Identity Repair with controlled keep, move, or unresolved disposition.
- Preserves recipe references unless a separate approved ingredient merge is performed.
- Moves purchase history and supplier packages only to an administrator-selected destination.
- Deduplicates equivalent supplier packages after a move.
- Keeps ambiguous purchase evidence in an unresolved archive rather than guessing.
- Adds an audit log for identity repairs.
- Displays low per-gram/per-ml costs with meaningful precision.
