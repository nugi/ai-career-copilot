# Architecture Decision Records

This directory records significant architecture decisions: the context, the decision and its consequences.

| #                                                       | Decision                                        | Status   |
| ------------------------------------------------------- | ----------------------------------------------- | -------- |
| [0001](0001-use-postgresql-with-pgvector.md)            | Use PostgreSQL with pgvector for vector storage | Accepted |
| [0002](0002-use-inertia-with-react-for-the-frontend.md) | Use Inertia with React for the frontend         | Accepted |

## Adding a record

1. Copy the structure of an existing record into `NNNN-short-title.md`, using the next number.
2. Fill in **Status**, **Date**, **Context**, **Decision** and **Consequences**.
3. Add the record to the table above.

The decision in an accepted record does not change. Clarifications are fine; to change a decision, write a new record and mark the old one as **Superseded by NNNN**.
