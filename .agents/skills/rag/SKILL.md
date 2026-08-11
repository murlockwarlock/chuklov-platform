---
name: rag
description: Implement organization-scoped knowledge ingestion and retrieval with pgvector. Use for document ingestion, chunking, embeddings, citations, retrieval, or KnowledgeRetriever adapters from Milestone 9 onward.
---

# RAG

1. Confirm the milestone permits RAG work, then load RAG requirements and `docs/architecture/rag.md`.
2. Work through the `KnowledgeRetriever` boundary and keep retrieval organization-scoped.
3. Use normalized supported documents; do not introduce raw DICOM processing.
4. Keep storage private and classify source content before embedding or provider transfer.
5. Make ingestion repeatable and versioned; handle replacement and deletion without orphaned chunks.
6. Return source attribution with retrieved evidence and constrain responses when evidence is insufficient.
7. Test cross-organization isolation, ingestion idempotency, ranking behavior, and no-result behavior with fakes where external embeddings would be required.
