# RAG

M9 implements organization knowledge retrieval behind the typed `KnowledgeRetriever` Application port. `PgvectorKnowledgeRetriever` derives the current Organization server-side, authorizes `ViewKnowledge`, applies organization and allowlisted source/type/category predicates before distance ordering, bounds top-K to 20, and returns structured chunk/source/revision/run provenance. It never builds an LLM prompt or answer.

## Sources and revisions

Phase 1 supports CRM-authored Markdown/plain text and approved private `.txt`, `.md`, and `.markdown` uploads. Uploads require both an allowlisted extension and server-detected `text/plain` or `text/markdown` MIME, are capped at 2 MB and 500,000 extracted characters, and remain on the private disk. OQ-008 keeps platform-shared method material out of scope. Client records, medical Sessions/attachments, conversations, survey answers, and other tenant clinical data are never automatically ingested.

`knowledge_sources` owns mutable title/category and active/retired availability. `knowledge_revisions` is immutable source evidence with monotonically increasing version, checksum, exact authored content or private file reference, MIME/size, source reference, author, and lifecycle timestamps. A source points to one active ready revision; replacement never rewrites historical revisions. Retirement removes a source and its revisions from future retrieval without deleting provenance or private bytes.

## Durable ingestion

The queue job carries Organization, source, and revision identifiers only. `ClaimKnowledgeIngestionRun` serializes competing workers with a PostgreSQL row lock and a unique `(organization_id, knowledge_revision_id, configuration_key)` identity. A processing lease may be reclaimed after 30 minutes. Failed retries delete only that run's partial chunks and rebuild deterministically; partial and failed runs are never retrieval-visible.

The normalized-character-window `v1` chunker normalizes line endings/whitespace, targets 1,200 characters, caps chunks at 1,600 characters, overlaps 160 characters, and preserves stable index, offsets, checksum, and source reference. Embeddings use the provider-neutral `EmbeddingGenerator` boundary. Each run records provider configuration name, model, dimension, configuration version, and chunk configuration without secrets. A complete run becomes ready and activates the revision in one transaction; a newer ready revision cannot be replaced by a late older worker.

## Retrieval and trust

PostgreSQL/pgvector is the only vector store. Phase 1 uses exact cosine distance (`<=>`) after relational Organization/source/revision/run predicates, then orders equal distances by chunk ID for bounded deterministic results. No approximate global vector index is created for the expected Phase-1 dataset. Stored and query embedding provider/model/dimension/configuration must match exactly or retrieval fails closed.

Retrieved content is untrusted data. Instruction-like text, URLs, commands, template syntax, and code are returned only as bounded content plus provenance; M9 executes none of them. M10 must keep system/developer instructions separate from these retrieval records and may later persist the stable chunk/source/revision references in AI-run provenance.

See REQ-RAG-001 through REQ-RAG-004 and ADR-007.
