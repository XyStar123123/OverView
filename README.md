# OverView

Platform self-hosted untuk mengelola, mengorganisasi, dan mengorkestrasi AI agents sebagai tenaga kerja digital.

OverView adalah lapisan koordinasi antara manusia, project, AI agents, external runtimes, dan automation workflows. LLM inference ditangani oleh external LLM gateway, agent execution ditangani oleh runtime/harness adapter, sementara seluruh koordinasi, pengelolaan pekerjaan, dan lifecycle agent ditangani oleh OverView.

📄 Dokumen lengkap: [PRD.md](./PRD.md)

## Konsep Utama

- **AI as Workforce** — agent diperlakukan sebagai anggota tim yang memiliki peran dan tanggung jawab.
- **Project-Centric Management** — semua aktivitas agent terorganisasi berdasarkan workspace, project, dan task.
- **Agent Interoperability** — mendukung berbagai agent framework dan harness.
- **Workflow Automation** — pekerjaan dapat dijalankan melalui workflow yang dapat dikonfigurasi.
- **Self-Hosted** — pengguna memiliki kendali penuh atas infrastruktur, data, dan execution environment.
- **Extensibility** — skills, tools, agents, dan integrations dapat dibuat atau diimpor.

## Arsitektur

| Layer | Tanggung Jawab |
|---|---|
| Presentation | React + TypeScript (dashboard, project UI, agent UI, workflow builder, monitoring) |
| Core Application | Laravel API: autentikasi, workspace, project, task, agent registry, permissions, audit log, event publishing |
| Orchestration | Task dispatching, delegation, workflow execution, approval, execution state, retry/recovery |
| Agent Runtime | Service terpisah (Python/TypeScript): agent loop, context assembly, LLM requests, tool calling, result submission |
| Integration | LLM gateway adapters, MCP client/server, runtime adapters, A2A |
| Infrastructure | PostgreSQL, Redis, object storage, queue workers, Docker sandbox, reverse proxy |

Prinsip arsitektur: modular, API-first, provider-agnostic, runtime-agnostic, event-driven, permission-first, persistent execution state, self-hostable.

## Modul

- Workspace management & data isolation
- Project management (specification, context, custom views)
- Task management (type, lifecycle, delegation, acceptance criteria)
- Agent management (identity, configuration, lifecycle, delegation)
- Agent registry (global, private, local)
- Skills & tools management + MCP
- LLM gateway management (adapter-based)
- Runtime & harness integration
- Workflow builder (trigger, agent, tool, condition, parallel, approval, delay nodes)
- Agent communication & collaboration
- Artifacts & deliverables
- Observability & execution monitoring
- Human approval & review
- Notifications & activity
- System administration

## Teknologi

| Komponen | Teknologi |
|---|---|
| Frontend | React + TypeScript, Vite, Native CSS / CSS Modules |
| Backend | Laravel |
| Agent Runtime | Python atau TypeScript |
| Database | PostgreSQL |
| Queue / Cache | Redis |
| File Storage | S3-compatible / MinIO |
| Realtime | SSE, WebSocket |
| Protocol | MCP, kemudian A2A |
| Deployment | Docker, Docker Compose |

## Roadmap

1. **Phase 1 — Core Foundation**: satu agent dapat menerima task, menggunakan tools yang diizinkan, mengirim hasil, dan memperbarui status task.
2. **Phase 2 — AI Workforce**: multi-agent, delegation, komunikasi, registry, skill & tool management.
3. **Phase 3 — Workflow Automation**: workflow builder, trigger, approval, parallel execution, retry & recovery.
4. **Phase 4 — Ecosystem**: registry publik/privat, MCP server & client, A2A, integrasi eksternal.

## Status Repository

Repository ini saat ini hanya berisi dokumen (PRD). Struktur kode (frontend, backend Laravel, runtime service, infrastructure) belum diinisialisasi.
