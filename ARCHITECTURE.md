# OverView — Prototype Architecture

**Dokumen:** Arsitektur Prototipe (vertical slice)  
**Sumber kebenaran produk:** `PRD.md` v1.1 (November 2026)  
**Status:** Binding contract untuk engineer backend & frontend. Kode/identifier dalam bahasa Inggris; prosa dokumen dalam bahasa Indonesia.

> Dokumen ini adalah kontrak implementasi untuk **prototipe Phase 1** (PRD §15) plus baseline modul Memory (PRD §6.16). Prototipe berjalan di mesin Windows dengan Laragon: `php artisan migrate --seed && php artisan serve` + `npm run dev`. Tidak ada LLM nyata, tidak ada worker eksternal, tidak ada Docker wajib.

---

## 1. Prototype Scope

### 1.1 Definisi vertical slice

Slice-nya mengikuti success condition PRD §15 Phase 1:

> An agent can receive a project task, retrieve relevant project information, perform work using authorized tools, submit results, and preserve useful knowledge for future tasks.

Ditambah baseline modul Memory sesuai PRD v1.1. Alur end-to-end yang harus jalan di prototipe:

1. User (seeded demo user) login → dashboard.
2. User membuka project, melihat board kanban task dengan status berbeda.
3. User membuka task, melihat config model inheritance (Workspace > Agent > Task override, PRD §6.7), toggle `Override Agent Model`.
4. User klik **Execute** → mock runner menjalankan agent loop (context assembly + memory retrieval berbudget → fake LLM → tool calls → artifact → memory proposal → task ke In Review).
5. User melihat execution detail: timeline event, usage, artifacts, memory yang dipakai.
6. User membuka Approvals: approve task review dan memory review (memory shared-scope tidak aktif sebelum approved — MAC-06).
7. User membuka Memory Explorer: filter scope/type/status, detail provenance + versi + riwayat.
8. Eksekusi task berikutnya di project yang sama mengambil memory yang sudah active → MAC-02.

### 1.2 In scope (konkret)

| Area | Yang diimplementasikan | Referensi PRD |
|---|---|---|
| Workspace & auth | 1 workspace, seeded demo user, session login, role `owner/admin/member/viewer` (role disimpan, enforcement minimum: viewer read-only untuk mutasi shared) | §6.1, §9.1 |
| Projects | CRUD, status/priority/objectives, anggota agent (`project_agents`), default views Overview + kanban/list | §6.2 (PRJ-001..013, 015) |
| Tasks | CRUD, assign agent, comment, type (feature/bug/fix/research/documentation/maintenance/investigation/custom), status lifecycle 7 state, `Override Agent Model` toggle, working-memory access | §6.3 (TASK-001..019, minus delegation) |
| Agents | Identity + config: role, instructions, tool allowlist, default gateway/model, runtime, memory config (scopes, retrieval limit, context budget), status lifecycle (derived from execution), activate/deactivate | §6.4 (AGT-001..012, 019..026) |
| Gateways | CRUD + connection test (mock) + model list (mock), credential sebagai `api_key_ref` (bukan plaintext) | §6.7 (LLM-001..004, 012) |
| Model hierarchy | Resolution `Task > Agent > Workspace`, dicatat di `executions.model_resolution` | §6.7, AC-13 |
| Runtimes | 1 runtime mock (`adapter: mock`), health status, capabilities JSON | §6.8 (RUN-001..005, mock only) |
| Executions | Lifecycle `queued→running→waiting→completed/failed/cancelled`, event log (types dari §6.12), usage (tokens fake, tool calls, retrieved memories), cancel (no-op aman untuk mock), recovery state persisted di DB | §6.12, §8.7 |
| Artifacts | Submit oleh runner, metadata, version 1, review accept/reject, download, tersimpan di local disk | §6.11 (ART-001..007, 009) |
| Memory | CRUD penuh, 4 type (working/episodic/semantic/procedural), 4 scope (workspace/project/agent/task), 8 lifecycle state, provenance metadata (§6.16.9), `memory_versions`, retrieval keyword-berbudget, tool `memory.search` / `memory.propose` / `memory.store` (+ `memory.get/list/update/archive/delete/history` sebagai operasi internal & API), Memory Explorer UI, approval gate untuk shared-scope (APR-009), scope isolation (MAC-03) | §6.16, §11.6 |
| Approvals | `task_review` (hasil agent → In Review) dan `memory_review` (memory shared-scope), approve/reject + alasan, siapa yang memutuskan tersimpan | §6.13 (APR-002..005, 007, 009, 010) |
| Audit | `audit_logs` untuk mutasi task, assignment, memory create/update/approve/reject/archive/delete, execution, review; actor identity (user/agent + execution) | §10.5, AC-10 |
| Dashboard | Statistik agregat ringan + recent activity (dari audit_logs) + pending approvals | §5.2 (subset) |

### 1.3 Out of scope (dengan referensi PRD)

| Di luar prototipe | Alasan / fase |
|---|---|
| Workflow builder, trigger, node, durable engine (Temporal) | §6.9, Phase 3 §15 |
| Agent registry (global/private/import, manifest, signing) | §6.5, Phase 4 §15 |
| Skill & tool management, MCP client/server | §6.6, Phase 4 §15 |
| Komunikasi agent-to-agent, A2A, delegation (AGT-013..018) | §6.10, Phase 2/4 §15 |
| LLM nyata / adapter gateway nyata (OmniRoute, LiteLLM, OpenAI-compatible) | §6.7 — konfigurasi + hierarki diimplementasikan, inferensi pakai mock runner; gateway "test" selalu sukses dengan pesan mock |
| Runtime eksternal (OpenHands, LangGraph, dsb.) | §6.8 — hanya `mock` adapter; contract adapter didokumentasikan di §5.5 |
| Semantic search via embedding/pgvector | §8.3, §6.16.6 — retrieval pakai keyword scoring deterministik; `MemorySearcher` interface sebagai titik tukar |
| Memory consolidation & auto-extraction pipeline | §6.16.5/6.16.8 — hanya `propose`/`store` manual; endpoint `extract`/`consolidate` tidak dibuat |
| Multi-workspace / cross-workspace sharing | §9.12 — 1 workspace; isolation tetap diterapkan pada query (semua resource punya `workspace_id`) |
| Notifications | §6.14 |
| Custom project views (user-defined) | PRJ-014 — hanya default views (PRJ-012/015) |
| Realtime SSE/WebSocket | §8.3 — UI mem-poll tiap 2 detik selama execution `queued/running` |
| Object storage S3/MinIO | §8.3 — local disk (`storage/app/overview/`) |
| Redis di produksi, queue worker terpisah, scheduler | §8.3 — `QUEUE_CONNECTION=sync` (lihat §2.2) |
| OIDC / multi-user register | §20 keputusan 10 — login local seeded user |
| Approval timeout enforcement | APR-006 — field `expires_at` disimpan, tidak ada job auto-expire |
| Budget enforcement eksekusi nyata | AGT-009/010 — field disimpan, mock runner tidak menghitung biaya nyata |

---

## 2. Keputusan Stack Lokal (Windows + Laragon)

Konteks: PRD §8.3 memandatkan React+TS+Vite, CSS Modules (tanpa UI framework), Laravel, PostgreSQL, Redis. Lingkungan development nyata: Windows + Laragon (PHP + Composer tersedia), **tidak** ada jaminan Postgres/Redis/Docker berjalan. Keputusan berikut menjaga boot command tetap `php artisan migrate --seed && php artisan serve` + `npm run dev`.

### 2.1 ADR-1 — SQLite untuk development, Postgres tetap target produksi

**Keputusan:** Default `.env` prototipe memakai `DB_CONNECTION=sqlite` (file `database/database.sqlite`). Semua Eloquent model, migration, dan query ditulis **portabel** (string enum, `json` column, `foreignId`, tanpa Postgres-specific type). Tidak ada `pgvector`/`uuid`/JSONB operator di kode.

**Justifikasi:**
- Laragon menyediakan PHP + `pdo_sqlite` OOTB; `php artisan migrate` langsung jalan tanpa service — memenuhi hard constraint boot command.
- Risiko: SQLite lock file saat concurrent write. Mitigasi: prototipe single-user demo + `foreign_key_constraints` + WAL default; volume write kecil.
- Apa yang mengubah keputusan: Postgres + pgvector diadopsi saat semantic search diimplementasikan (Phase 2); saat itu `DB_CONNECTION=pgsql` lewat `.env` tanpa ubah skema, kecuali fitur vector.

### 2.2 ADR-2 — Redis digantikan `QUEUE_CONNECTION=sync` + cache `file`

**Keputusan:** `.env` default: `QUEUE_CONNECTION=sync`, `CACHE_STORE=file`, `SESSION_DRIVER=file`. Tidak ada Redis untuk boot.

**Justifikasi:**
- Mock runner deterministik dan cepat (~beberapa detik, semua in-process). Sync queue berarti `POST /tasks/{id}/execute` menjalankan runner sebelum response kembali — UI langsung menampilkan state final; tidak perlu `php artisan queue:work` di Windows.
- `execution_events` + `executions` persisted di DB, jadi state tidak hilang saat server restart (RUN-010, AC-08 sebatas prototipe).
- Titik tukar: `QUEUE_CONNECTION=database` (tambah tabel `jobs`) bila runner di-async-kan; interface tidak berubah.

### 2.3 ADR-3 — Gateway & LLM mock, bukan gateway nyata

**Keputusan:** Gateway seeded bertipe `mock`. `POST /gateways/{id}/test` mengembalikan hasil sukses dengan pesan `"mock connection ok"` tanpa HTTP call. `CannedLlmClient` menghasilkan text deterministik dari input (bukan LLM nyata, tidak ada network call). Credential hanya disimpan sebagai `api_key_ref` (string), mematuhi LLM-012.

### 2.4 ADR-4 — `docker-compose.yml` opsional, non-blocking

Sediakan `docker-compose.yml` di root **hanya untuk developer yang punya Docker**: service `postgres:16` (port 5432) dan `redis:7` (port 6379). Tidak mengubah kode; dipakai lewat `.env` swap: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=overview`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`. Compose **bukan** prasyarat boot prototipe.

### 2.5 Konvensi lingkungan (binding)

| Item | Nilai |
|---|---|
| PHP | 8.2+ (Laragon), Laravel 11/12 (skeleton default `composer create-project laravel/laravel backend`) |
| Node | 20+, Vite 5+, React 18 |
| Backend port | 8000 (`php artisan serve`) |
| Frontend port | 5173 (Vite dev), proxy `/api` → `http://localhost:8000` |
| `.env` backend (committed defaults) | `APP_ENV=local`, `APP_DEBUG=true`, `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=file`, `SESSION_DRIVER=file`, `APP_URL=http://localhost:8000` |
| CORS | `config/cors.php`: allow origin `http://localhost:5173`, `supports_credentials=true` (session cookie dari Vite origin) |
| Package Composer tambahan | **Tidak ada** — hanya skeleton Laravel (tanpa Sanctum/Fortify/Filament); auth via session API sederhana |
| Dependensi JS | `react`, `react-dom`, `react-router-dom` (router dipilih karena multi-page app; satu-satunya library UI yang diizinkan), `vite`, `typescript` + `@types/*`. **Tidak** ada library UI/styling (PRD §8.3: native CSS/CSS Modules) |

---

## 3. Data Model (prototipe-scale)

Prinsip: satu tabel per concern, string enum (portabel SQLite/Postgres), semua resource workspace-owned punya `workspace_id` (isolation §9.12), `memories` mengikuti aturan isolation yang sama. PHP enum di `app/Enums/*`; DB menyimpan string.

### 3.1 Identity & Workspace

**`users`**
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| name | string | |
| email | string unique | |
| password_hash | string | |
| created_at / updated_at | timestamp | |

**`workspaces`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| name | string |
| slug | string unique |
| description | string nullable |
| default_gateway_id | FK → gateways nullable |
| default_model | string nullable |
| default_runtime_id | FK → runtimes nullable |
| memory_policy | json default `{ "require_review_for_shared_scopes": true }` |
| created_at / updated_at | timestamp |

**`workspace_members`**: `id`, `workspace_id` FK, `user_id` FK, `role` string enum(`owner`,`admin`,`member`,`viewer`), unique(`workspace_id`,`user_id`).

### 3.2 Projects

**`projects`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id | FK |
| slug | string, unique per workspace |
| name | string |
| description | text |
| objectives | json (array of string) |
| status | string enum(`planning`,`active`,`paused`,`completed`,`archived`) |
| priority | string enum(`low`,`medium`,`high`) |
| start_date / due_date | date nullable |
| owner_user_id | FK → users |
| created_at / updated_at | timestamp |

**`project_members`**: `id`, `project_id` FK, `user_id` FK, unique pair.  
**`project_agents`**: `id`, `project_id` FK, `agent_id` FK, unique pair. *(dasar aturan scope isolation MAC-03: agent hanya melihat project memory dari project yang ia anggotai.)*

### 3.3 Tasks (status & type lifecycle PRD §6.3)

**`tasks`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id | FK |
| project_id | FK |
| parent_id | FK → tasks nullable |
| title | string |
| description | text |
| type | string enum(`feature`,`bug`,`fix`,`research`,`documentation`,`maintenance`,`investigation`,`custom`) |
| type_custom | string nullable (jika `type=custom`) |
| status | string enum(`todo`,`in_progress`,`pending`,`blocked`,`in_review`,`done`,`cancelled`) |
| priority | string enum(`low`,`medium`,`high`) |
| assignee_type | string enum(`user`,`agent`) nullable |
| assignee_id | bigint nullable (user_id atau agent_id sesuai `assignee_type`) |
| creator_type | string enum(`user`,`agent`) |
| creator_id | bigint |
| due_date | date nullable |
| labels | json (array of string) |
| override_agent_model | boolean default false *(LLM-016/AC-13)* |
| override_gateway_id | FK → gateways nullable |
| override_model | string nullable |
| created_at / updated_at | timestamp |

> Model override disimpan **langsung di `tasks`** (bukan tabel terpisah `task_model_overrides`) — prototype-scale; hierarchy di §3.10.

**`task_comments`**: `id`, `task_id` FK, `actor_type` (`user`|`agent`), `actor_id`, `body` text, `execution_id` FK nullable, `created_at`.

Status lifecycle default: `todo → in_progress → pending → blocked ⇄ in_progress → in_review → done | (kembali in_progress)` dan `cancelled` dari state mana pun. Runner menggerakkan `in_progress → in_review`; review approve → `done`; reject → `in_progress` + comment berisi alasan.

### 3.4 Agents (identity + config, PRD §6.4)

**`agents`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id | FK |
| name / slug | string (slug unique per workspace) |
| avatar | string nullable (URL/path) |
| description | text |
| role | string (mis. `backend_developer`, `qa_engineer`, `researcher`) |
| instructions | text nullable |
| version | int default 1 *(AGT-012: versioning sederhana)* |
| status | string enum(`draft`,`available`,`working`,`blocked`,`waiting_for_approval`,`offline`,`disabled`,`error`) — derived dari execution state (§5.5) |
| gateway_id | FK → gateways |
| default_model | string |
| runtime_id | FK → runtimes |
| tool_allowlist | json (array of tool key, mis. `["task.update","artifact.submit","memory.search","memory.propose","memory.store","memory.get","memory.list"]`) |
| memory_enabled | boolean default true |
| allowed_memory_scopes | json array subset(`workspace`,`project`,`agent`,`task`) |
| memory_retrieval | json `{ "enabled": true, "max_memories": 5, "context_budget_tokens": 2000 }` |
| execution_budget | json nullable `{ "max_executions_per_task": 3 }` |
| concurrency_limit | int default 1 |
| created_by | bigint (user id) |
| created_at / updated_at | timestamp |

> Memory config, model config, tool list, budget, concurrency disimpan sebagai column JSON di `agents` (PRD §9.4 memecahnya jadi banyak tabel; prototipe menggabungkan — catatan eksplisit di dokumen ini, bukan deviasi diam-diam).

### 3.5 Gateways & Runtimes

**`gateways`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id | FK |
| name | string |
| type | string enum(`mock`,`openai_compatible`,`litellm`,`omni_route`,`custom`) |
| base_url | string |
| api_key_ref | string *(referensi secret, bukan plaintext — LLM-012; mock: `MOCK_KEY_REF`)* |
| api_format | string default `chat` |
| status | string enum(`unknown`,`connected`,`error`) |
| models | json (array of model alias) |
| last_checked_at | timestamp nullable |
| created_at / updated_at | timestamp |

**`runtimes`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id | FK |
| name | string |
| adapter | string enum(`mock`,`openhands`,`langgraph`,`custom`) |
| status | string enum(`unknown`,`healthy`,`degraded`,`unreachable`) |
| capabilities | json array (`context_injection`,`tool_based_memory_access`,`execution_state_recovery`) |
| created_at / updated_at | timestamp |

### 3.6 Executions & Events

**`executions`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id, project_id, task_id, agent_id | FK |
| runtime_id, gateway_id | FK |
| model | string (effective model, hasil resolution) |
| model_resolution | json `{ "gateway": "mock-gateway", "model": "mock-llm-1", "source": "agent" }` (`source` ∈ `workspace`|`agent`|`task`) — auditability LLM-019 |
| status | string enum(`queued`,`running`,`waiting`,`completed`,`failed`,`cancelled`) |
| started_at / finished_at | timestamp nullable |
| usage | json `{ "input_tokens": 0, "output_tokens": 0, "context_tokens": 0, "tool_invocations": 0, "retrieved_memory_count": 0, "estimated_cost": 0.0, "duration_ms": 0 }` |
| failure_reason | string nullable |
| created_at | timestamp |

**`execution_events`**: `id`, `execution_id` FK, `sequence` int, `type` string enum(`task_received`,`agent_initialized`,`context_assembled`,`memory_retrieval`,`memory_stored`,`memory_rejected`,`llm_request`,`llm_response`,`tool_requested`,`tool_executed`,`tool_rejected`,`approval_requested`,`artifact_submitted`,`task_status_updated`,`execution_completed`,`execution_failed`,`error`), `payload` json, `created_at`. Index `(execution_id, sequence)`.

### 3.7 Artifacts

**`artifacts`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id, project_id, task_id, execution_id, agent_id | FK |
| name | string |
| artifact_type | string enum(`code`,`document`,`report`,`image`,`data`,`other`) |
| file_path | string (di `storage/app/overview/`) |
| content_type | string |
| size_bytes | int |
| version | int default 1 |
| status | string enum(`submitted`,`reviewed`,`accepted`,`rejected`) |
| reviewed_by | bigint nullable (user id) |
| review_note | text nullable |
| created_at / updated_at | timestamp |

### 3.8 Memories (PRD §6.16 + §9.9, digabung prototype-scale)

**`memories`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id | FK |
| scope | string enum(`workspace`,`project`,`agent`,`task`) |
| project_id | FK nullable (wajib jika scope=`project`) |
| agent_id | FK nullable (wajib jika scope=`agent`) |
| task_id | FK nullable (wajib jika scope=`task`) |
| execution_id | FK nullable *(provenance: dari execution mana)* |
| type | string enum(`working`,`episodic`,`semantic`,`procedural`) |
| title | string |
| content | text |
| status | string enum(`candidate`,`pending_review`,`approved`,`active`,`superseded`,`archived`,`rejected`,`expired`) |
| version | int default 1 |
| confidence | decimal(3,2) default 0.5 (0–1) |
| trust | string enum(`high`,`medium`,`low`) *(MEM-051)* |
| source | json `{ "type": "execution"|"task"|"user"|"document"|"artifact", "ref_id": 0, "actor_type": "agent"|"user", "actor_id": 0, "summary": "" }` |
| created_by_type | string enum(`user`,`agent`,`system`) |
| created_by_id | bigint |
| approved_by | bigint nullable (user id) |
| approved_at | timestamp nullable |
| expires_at | timestamp nullable |
| last_accessed_at | timestamp nullable |
| created_at / updated_at | timestamp |

**`memory_versions`** (MAC-05): `id`, `memory_id` FK, `version` int, `content` text, `changed_by_type` (`user`|`agent`|`system`), `changed_by_id` bigint, `reason` string nullable, `created_at`. Setiap perubahan `content`/`status` penting menaruh satu row baru; `memories` selalu menyimpan versi terkini.

**`memory_retrievals`** (OBS-011, MEM-037): `id`, `execution_id` FK, `memory_id` FK, `rank` int, `score` float, `retrieved_at` timestamp. Index `(execution_id)`, `(memory_id)`.

> `memory_sources` (PRD §9.9) digabung ke `memories.source` (json); `memory_policies` digabung ke `workspaces.memory_policy`; `memory_access_logs` digantikan `memory_retrievals` + `audit_logs`; `memory_embeddings`, `memory_relations`, `memory_consolidation_runs` **tidak dibuat** (out of scope §1.3).

### 3.9 Approvals & Audit

**`approvals`**
| Kolom | Tipe |
|---|---|
| id | bigint PK |
| workspace_id | FK |
| request_type | string enum(`task_review`,`memory_review`) |
| task_id | FK nullable (jika `task_review`) |
| memory_id | FK nullable (jika `memory_review`) |
| execution_id | FK nullable |
| status | string enum(`pending`,`approved`,`rejected`,`expired`) |
| requested_by_type | string enum(`agent`,`user`,`system`) |
| requested_by_id | bigint |
| decision_user_id | FK → users nullable |
| decision_reason | text nullable |
| decided_at | timestamp nullable |
| expires_at | timestamp nullable |
| created_at | timestamp |

**`audit_logs`** (§10.5): `id`, `workspace_id`, `actor_type` (`user`|`agent`|`system`), `actor_id`, `resource_type` string (mis. `task`,`memory`,`execution`,`gateway`), `resource_id` int, `action` string (mis. `task.status_changed`,`memory.created`,`memory.approved`,`execution.completed`,`task.reviewed`,`gateway.created`), `before` json nullable, `after` json nullable, `execution_id` FK nullable, `created_at`.

### 3.10 Aturan binding: hierarki model (PRD §6.7) & isolation memory (MAC-03)

**Model resolution** (dieksekusi saat execution dibuat, dicatat di `executions.model_resolution`):

```
effective = task.override_agent_model ON  → (task.override_gateway_id, task.override_model)   [source: "task"]
          else agent.gateway_id/default_model present                                          [source: "agent"]
          else workspace.default_gateway_id/default_model                                      [source: "workspace"]
```

Override **tidak pernah** menuliskan ulang `agents.gateway_id`/`default_model` (LLM-018, AC-13). Tanpa workflow di prototipe, level `Workflow Node` (posisi 4) tidak ada; kontrak tetap memungkasnya dengan `model_resolution.source`.

**Scope isolation** (MAC-03, di-enforce di `MemoryService.search` — diuji di §5.4):
- `scope=workspace`: hanya memory milik workspace yang sama; hanya agent yang `workspace` ada di `allowed_memory_scopes`.
- `scope=project`: hanya project yang agent-nya terikat lewat `project_agents` **dan** task-nya berada di project itu. Dua project tidak berhubungan → memory tidak bocor.
- `scope=agent`: hanya agent pemilik (agent lain tidak bisa baca — PRD §6.16.3).
- `scope=task`: hanya task/execution pemilik (working memory, §6.16.3 Task/Execution Memory).
- Semua pencarian hanya mengembalikan `status=active` (working memory scope task memakai `active` juga; `candidate/pending_review` tidak pernah masuk context — MAC-06).
- Memory **tidak pernah** menambah permission (MEM-050): allowlist tool & scope diambil dari `agents`, bukan dari isi memory.

---

## 4. API Contract

### 4.1 Konvensi

- Namespace `/api/v1` (PRD §11). Semua route JSON; auth session cookie (bukan token).
- Auth: `POST /auth/login` mengisi Laravel session; request API membawa cookie (`credentials: "include"` + CORS supports_credentials dari §2.5). Tanpa session → `401`.
- Semua resource query di-scope oleh workspace aktif (single workspace: tidak perlu select header; `workspace_id` dari session user).
- Timestamp ISO-8601 UTC; id numerik.

**Error shape (binding, semua error 4xx/5xx):**

```ts
type ApiError = {
  error: {
    code:
      | "unauthorized"        // 401
      | "forbidden"           // 403
      | "not_found"           // 404
      | "validation_failed"  // 422, details berisi field errors
      | "conflict"           // 409, mis. task sudah punya execution running (TASK-015)
      | "invalid_state"      // 422, transisi status/agent tidak valid
      | "internal";          // 500
    message: string;
    details?: Record<string, unknown>;
  };
};
```

Shared types (TypeScript-flavored, dipakai di `frontend/src/api/types.ts`):

```ts
type Role = "owner" | "admin" | "member" | "viewer";
type TaskType = "feature" | "bug" | "fix" | "research" | "documentation" | "maintenance" | "investigation" | "custom";
type TaskStatus = "todo" | "in_progress" | "pending" | "blocked" | "in_review" | "done" | "cancelled";
type ProjectStatus = "planning" | "active" | "paused" | "completed" | "archived";
type AgentStatus = "draft" | "available" | "working" | "blocked" | "waiting_for_approval" | "offline" | "disabled" | "error";
type ExecutionStatus = "queued" | "running" | "waiting" | "completed" | "failed" | "cancelled";
type MemoryScope = "workspace" | "project" | "agent" | "task";
type MemoryType = "working" | "episodic" | "semantic" | "procedural";
type MemoryStatus = "candidate" | "pending_review" | "approved" | "active" | "superseded" | "archived" | "rejected" | "expired";
type ApprovalStatus = "pending" | "approved" | "rejected" | "expired";
type Priority = "low" | "medium" | "high";

interface User { id: number; name: string; email: string; }
interface Workspace {
  id: number; name: string; slug: string; description: string | null;
  defaultGatewayId: number | null; defaultModel: string | null; defaultRuntimeId: number | null;
  memoryPolicy: { requireReviewForSharedScopes: boolean };
}
interface Project {
  id: number; workspaceId: number; slug: string; name: string; description: string;
  objectives: string[]; status: ProjectStatus; priority: Priority;
  startDate: string | null; dueDate: string | null; ownerUserId: number;
  agentCount: number; taskCount: number; // computed
}
interface TaskModelOverride {
  enabled: boolean;                       // override_agent_model
  gatewayId: number | null;
  model: string | null;
  resolvedSource: "task" | "agent" | "workspace"; // level yang menyuplai model efektif (jika enabled=false)
}
interface Task {
  id: number; projectId: number; parentTaskId: number | null;
  title: string; description: string; type: TaskType; customType: string | null;
  status: TaskStatus; priority: Priority;
  assignee: { type: "user" | "agent"; id: number } | null;
  createdBy: { type: "user" | "agent"; id: number };
  dueDate: string | null; labels: string[];
  modelOverride: TaskModelOverride;
  hasRunningExecution: boolean; // computed: ada execution queued/running
  createdAt: string; updatedAt: string;
}
interface TaskComment { id: number; taskId: number; actor: { type: "user" | "agent"; id: number }; body: string; executionId: number | null; createdAt: string; }
interface Agent {
  id: number; name: string; slug: string; avatar: string | null; description: string;
  role: string; instructions: string | null; version: number; status: AgentStatus;
  gatewayId: number; defaultModel: string; runtimeId: number;
  toolAllowlist: string[];
  memory: {
    enabled: boolean;
    allowedScopes: MemoryScope[];
    retrieval: { enabled: boolean; maxMemories: number; contextBudgetTokens: number };
  };
  executionBudget: { maxExecutionsPerTask: number } | null;
  concurrencyLimit: number;
  createdAt: string; updatedAt: string;
}
interface Gateway {
  id: number; name: string; type: string; baseUrl: string; apiKeyRef: string;
  apiFormat: string; status: "unknown" | "connected" | "error";
  models: string[]; lastCheckedAt: string | null;
}
interface Runtime { id: number; name: string; adapter: string; status: string; capabilities: string[]; }
interface Execution {
  id: number; taskId: number; projectId: number; agentId: number; runtimeId: number;
  gatewayId: number; model: string;
  modelResolution: { gateway: string; model: string; source: "workspace" | "agent" | "task" };
  status: ExecutionStatus; startedAt: string | null; finishedAt: string | null;
  usage: {
    inputTokens: number; outputTokens: number; contextTokens: number;
    toolInvocations: number; retrievedMemoryCount: number;
    estimatedCost: number; durationMs: number;
  };
  failureReason: string | null; createdAt: string;
}
type ExecutionEventType =
  | "task_received" | "agent_initialized" | "context_assembled" | "memory_retrieval"
  | "memory_stored" | "memory_rejected" | "llm_request" | "llm_response"
  | "tool_requested" | "tool_executed" | "tool_rejected" | "approval_requested"
  | "artifact_submitted" | "task_status_updated" | "execution_completed"
  | "execution_failed" | "error";
interface ExecutionEvent { id: number; executionId: number; sequence: number; type: ExecutionEventType; payload: Record<string, unknown>; createdAt: string; }
interface Artifact {
  id: number; taskId: number; executionId: number; agentId: number;
  name: string; artifactType: string; contentType: string; sizeBytes: number;
  version: number; status: "submitted" | "reviewed" | "accepted" | "rejected";
  reviewedBy: number | null; reviewNote: string | null; createdAt: string;
}
interface MemorySource { type: "execution" | "task" | "user" | "document" | "artifact"; refId: number | null; actorType: "agent" | "user"; actorId: number; summary: string; }
interface Memory {
  id: number; scope: MemoryScope; type: MemoryType;
  projectId: number | null; agentId: number | null; taskId: number | null; executionId: number | null;
  title: string; content: string; status: MemoryStatus; version: number;
  confidence: number; trust: "high" | "medium" | "low";
  source: MemorySource;
  createdBy: { type: "user" | "agent" | "system"; id: number };
  approvedBy: number | null; approvedAt: string | null;
  expiresAt: string | null; lastAccessedAt: string | null;
  createdAt: string; updatedAt: string;
}
interface MemoryVersion { id: number; memoryId: number; version: number; content: string; changedBy: { type: string; id: number }; reason: string | null; createdAt: string; }
interface Approval {
  id: number; requestType: "task_review" | "memory_review";
  taskId: number | null; memoryId: number | null; executionId: number | null;
  status: ApprovalStatus;
  requestedBy: { type: "agent" | "user" | "system"; id: number };
  decisionUserId: number | null; decisionReason: string | null;
  decidedAt: string | null; expiresAt: string | null; createdAt: string;
}
```

### 4.2 Routes

#### Auth
| Method & path | Request | Response |
|---|---|---|
| `POST /auth/login` | `{ email: string; password: string }` | `200 { user: User, workspace: Workspace }` · 401 `unauthorized` |
| `POST /auth/logout` | – | `204` |
| `GET /auth/me` | – | `{ user: User, role: Role }` |

#### Workspaces
| Method & path | Request | Response |
|---|---|---|
| `GET /workspaces` | – | `{ data: Workspace[] }` |
| `GET /workspaces/{id}` | – | `{ data: Workspace }` |
| `PATCH /workspaces/{id}` | `{ name?; description?; defaultGatewayId?; defaultModel?; defaultRuntimeId?; memoryPolicy? }` | `{ data: Workspace }` |
| `GET /workspaces/{id}/members` | – | `{ data: { user: User; role: Role }[] }` |

#### Dashboard
`GET /dashboard/stats` →
```ts
{
  totals: { projects: number; activeProjects: number; agents: number; activeAgents: number; memories: number; pendingMemoryReviews: number };
  executions: { running: number; completedLast7Days: number; failedLast7Days: number };
  tasks: { blocked: number; inReview: number; completedLast7Days: number };
  pendingApprovals: number;
  recentActivity: { id: number; actor: { type: "user" | "agent" | "system"; id: number }; action: string; resourceType: string; resourceId: number; at: string }[]; // max 20, dari audit_logs
}
```
(Disesengkan untuk §14.1: maksimal ~6 KPI card + 1 activity list, tanpa metric overload.)

#### Projects
| Method & path | Request | Response |
|---|---|---|
| `GET /projects` | query: `status?` | `{ data: Project[] }` |
| `POST /projects` | `{ name; slug?; description?; objectives?: string[]; status?; priority?; agentIds?: number[] }` | `201 { data: Project }` |
| `GET /projects/{id}` | – | `{ data: Project }` |
| `PATCH /projects/{id}` | subset field project | `{ data: Project }` |
| `DELETE /projects/{id}` | – | `204` (hanya jika archived) |
| `GET /projects/{id}/agents` | – | `{ data: Agent[] }` |
| `POST /projects/{id}/agents` | `{ agentId: number }` | `201 { data: { projectId; agentId } }` |
| `GET /projects/{id}/memory` | query: `type? status?` | `{ data: Memory[] }` (scope=project ini) |
| `GET /projects/{id}/tasks` | query: `status? type?` | `{ data: Task[] }` |
| `POST /projects/{id}/tasks` | `{ title; description?; type?; priority?; assignee?: { type; id }; dueDate?; labels?: string[]; overrideAgentModel?; overrideGatewayId?; overrideModel? }` | `201 { data: Task }` |

#### Tasks
| Method & path | Request | Response |
|---|---|---|
| `GET /tasks/{id}` | – | `{ data: Task }` |
| `PATCH /tasks/{id}` | subset field task (status, priority, labels, assignee, model override via `PATCH /tasks/{id}/model-override` — tidak lewat PATCH umum) | `{ data: Task }` |
| `POST /tasks/{id}/assign` | `{ assignee: { type: "user" \| "agent"; id: number } }` | `200 { data: Task }` · 409 `conflict` |
| `POST /tasks/{id}/comments` | `{ body: string }` | `201 { data: TaskComment }` |
| `GET /tasks/{id}/comments` | – | `{ data: TaskComment[] }` (asc) |
| `POST /tasks/{id}/execute` | `{ memorySearchQuery?: string }` (default: task title) | `202 { data: Execution, message: "execution started" }` · 409 `conflict` (sudah ada execution queued/running — TASK-015) · 422 `invalid_state` (status agent tidak aktif / task tidak assignable) |
| `POST /tasks/{id}/review` | `{ decision: "approve" \| "reject"; reason?: string }` | `200 { data: Approval, task: Task }` |
| `PATCH /tasks/{id}/model-override` | `{ enabled: boolean; gatewayId?: number \| null; model?: string \| null }` | `{ data: TaskModelOverride }` · 422 jika `enabled=true` tanpa `gatewayId`+`model` |
| `GET /tasks/{id}/artifacts` | – | `{ data: Artifact[] }` |
| `GET /tasks/{id}/memory` | – | `{ data: { working: Memory[]; proposed: Memory[] } }` (working: scope=task & taskId; proposed: memory dari execution task ini) |
| `GET /tasks/{id}/executions` | – | `{ data: Execution[] }` |

#### Agents
| Method & path | Request | Response |
|---|---|---|
| `GET /agents` | query: `status?` | `{ data: Agent[] }` |
| `POST /agents` | `{ name; role; description?; instructions?; gatewayId; defaultModel; runtimeId; toolAllowlist?: string[]; memory?: Agent["memory"]; executionBudget?; concurrencyLimit? }` | `201 { data: Agent }` (status awal `draft`) |
| `GET /agents/{id}` | – | `{ data: Agent }` |
| `PATCH /agents/{id}` | subset field agent | `{ data: Agent }` |
| `DELETE /agents/{id}` | – | `204` (memory agent tetap ada — AGT-026, MAC-04) |
| `POST /agents/{id}/activate` | – | `200 { data: Agent }` (status→`available`) |
| `POST /agents/{id}/deactivate` | – | `200 { data: Agent }` (status→`offline`) |
| `GET /agents/{id}/executions` | query: `status?` | `{ data: Execution[] }` |
| `GET /agents/{id}/memory` | – | `{ data: Memory[] }` (scope=agent & agentId) |
| `PATCH /agents/{id}/memory-config` | `{ enabled?; allowedScopes?; retrieval? }` | `{ data: Agent["memory"] }` |

#### Gateways & Runtimes
| Method & path | Request | Response |
|---|---|---|
| `GET /gateways` | – | `{ data: Gateway[] }` |
| `POST /gateways` | `{ name; type; baseUrl; apiKeyRef; apiFormat?; models?: string[] }` | `201 { data: Gateway }` |
| `PATCH /gateways/{id}` | subset | `{ data: Gateway }` |
| `POST /gateways/{id}/test` | – | `{ ok: true, message: "mock connection ok", latencyMs: number, models: string[] }` (mock: selalu sukses; AC-03 tanpa bocorkan secret) |
| `GET /gateways/{id}/models` | – | `{ data: { models: string[]; discovered: false } }` |
| `GET /runtimes` | – | `{ data: Runtime[] }` |
| `POST /runtimes` | `{ name; adapter; capabilities?: string[] }` | `201 { data: Runtime }` |
| `POST /runtimes/{id}/health` | – | `{ healthy: true, status: "healthy" }` |

#### Executions
| Method & path | Request | Response |
|---|---|---|
| `GET /executions` | query: `agentId? projectId? status?` | `{ data: Execution[] }` (desc) |
| `GET /executions/{id}` | – | `{ data: Execution }` |
| `GET /executions/{id}/events` | query: `afterSequence?` | `{ data: ExecutionEvent[] }` (asc sequence) |
| `GET /executions/{id}/artifacts` | – | `{ data: Artifact[] }` |
| `GET /executions/{id}/memories` | – | `{ data: { retrieved: (Memory & { rank: number; score: number })[]; proposed: Memory[] } }` |
| `GET /executions/{id}/model-resolution` | – | `{ data: Execution["modelResolution"] }` |
| `POST /executions/{id}/cancel` | – | `200 { data: Execution }` (status→`cancelled`; mock: hanya valid jika masih `queued`; execution running mock tidak bisa dipaksa berhenti → 422 `invalid_state` + pesan bahwa mock tidak mendukung cancel) |

#### Artifacts
| Method & path | Request | Response |
|---|---|---|
| `GET /artifacts/{id}` | – | `{ data: Artifact }` |
| `GET /artifacts/{id}/download` | – | binary file, `Content-Disposition: attachment` |
| `POST /artifacts/{id}/review` | `{ decision: "accept" \| "reject"; note?: string }` | `{ data: Artifact }` |

#### Memories
Semua enforce workspace + scope authorization (PRD §11.6). Query list: `scope? type? status? projectId? agentId? q?`.

| Method & path | Request | Response |
|---|---|---|
| `GET /memories` | query di atas | `{ data: Memory[] }` |
| `POST /memories` | `{ scope; type; title; content; projectId?; agentId?; taskId?; confidence?; trust? }` (buatan user manual — langsung `active`) | `201 { data: Memory }` |
| `GET /memories/{id}` | – | `{ data: Memory & { versions: MemoryVersion[]; retrievals: { executionId: number; at: string; rank: number }[] } }` |
| `PATCH /memories/{id}` | `{ content?; title?; confidence?; expiresAt? }` | `{ data: Memory }` (menulis `memory_versions` — MAC-05) |
| `DELETE /memories/{id}` | – | `204` (ownership/retention check → 403; juga menghapus derived `memory_retrievals` — MAC-08; audit) |
| `POST /memories/search` | `{ query: string; scope?: MemoryScope; type?: MemoryType; status?: MemoryStatus; projectId?: number; agentId?: number; limit?: number (default 10) }` | `{ data: { results: (Memory & { score: number; rank: number })[]; total: number } }` — mirror tool `memory.search`; di sisi user: keyword + filter; di sisi runner: ditambah scope isolation §3.10 |
| `POST /memories/{id}/approve` | – | `{ data: Memory, approval: Approval }` (status→`active`; approval terkait → `approved`) |
| `POST /memories/{id}/reject` | `{ reason?: string }` | `{ data: Memory, approval: Approval }` (status→`rejected`) |
| `POST /memories/{id}/archive` | – | `{ data: Memory }` (status→`archived`) |
| `POST /memories/{id}/supersede` | `{ content: string; reason?: string }` | `{ data: Memory }` (versi lama→`superseded` tercatat di `memory_versions`, content baru→versi+1 — §6.16.4) |
| `GET /memories/{id}/versions` | – | `{ data: MemoryVersion[] }` |
| `GET /memories/{id}/sources` | – | `{ data: Memory["source"] & { execution?: Execution; task?: Task } }` |
| `GET /memories/{id}/history` | – | `{ data: { event: string; at: string; actor: { type: string; id: number } }[] }` (dari audit_logs) |

#### Approvals & Audit
| Method & path | Request | Response |
|---|---|---|
| `GET /approvals` | query: `status? requestType?` | `{ data: Approval[] }` (pending di depan, desc) |
| `GET /approvals/{id}` | – | `{ data: Approval }` |
| `POST /approvals/{id}/approve` | – | `{ data: Approval }` (menyeberkan efek: task→`done` / memory→`active`; APR-007: `decisionUserId` = user session) |
| `POST /approvals/{id}/reject` | `{ reason?: string }` | `{ data: Approval }` (task→`in_progress` + comment alasan / memory→`rejected`; APR-004) |
| `GET /audit-logs` | query: `resourceType? resourceId? limit?` | `{ data: { id; actorType; actorId; resourceType; resourceId; action; before: unknown; after: unknown; executionId: number \| null; at: string }[] }` |

### 4.3 Seed data (spec binding)

`DatabaseSeeder` → `DemoSeeder`, deterministik (tanpa random, timestamp relatif ke `now()`). Hasil seed:

| Entity | Data |
|---|---|
| users | `demo` — `demo@overview.local` / password `demo` (1 user; role `owner`) |
| workspaces | `ws1` "Demo Workspace", `default_gateway_id=g1`, `default_model="mock-llm-lite"`, `default_runtime_id=r1`, `memory_policy.require_review_for_shared_scopes=true` |
| gateways | `g1` "Mock Gateway" type=`mock`, base_url=`mock://local`, api_key_ref=`MOCK_KEY_REF`, status=`connected`, models=`["mock-llm-1","mock-llm-lite"]` |
| runtimes | `r1` "Mock Runner" adapter=`mock`, status=`healthy`, capabilities=`["context_injection","tool_based_memory_access","execution_state_recovery"]` |
| projects | `p1` "OverView Core" (active, medium, objectives 2 item), `p2` "Website Redesign" (planning, high) |
| project_agents | a1,a2 → p1; a3 → p2 |
| agents | `a1` "Backend Developer" (available, gateway g1, model `mock-llm-1`, tools `[task.update, artifact.submit, memory.search, memory.propose, memory.store, memory.get, memory.list]`, memory scopes `[agent, project, task]`, retrieval `{enabled: true, max_memories: 5, context_budget_tokens: 2000}`); `a2` "QA Engineer" (available, model `mock-llm-lite`, scope `[project, task]`); `a3` "Researcher" (offline, scope `[workspace, project]`) |
| tasks | `t1` p1 "Draft public API contract" (feature, **todo**, high, assignee a1, `override_agent_model=true` → g1/`mock-llm-lite` untuk demo AC-13); `t2` p1 "Implement session auth" (feature, **in_progress**, medium, a1); `t3` p1 "Write project memory guidelines" (documentation, **in_review**, medium, a1, punya execution `e1` completed + approval `ap1` pending + artifact `ar1` + working memory `m5` + proposed `m6`); `t4` p1 "Fix kanban board drag" (bug, **done**, low, a2) |
| executions | `e1` (t3, a1) status=`completed`, `model_resolution={gateway:"mock-gateway", model:"mock-llm-1", source:"agent"}`, usage={input 412, output 188, context 1240, tool_invocations 3, retrieved_memory_count 2, estimated_cost 0, duration_ms 3200}, ~13 events sesuai §5.3, 2 rows `memory_retrievals` (m2, m4) |
| artifacts | `ar1` "memory-guidelines-draft.md" (document, task t3, exec e1, status `reviewed`); file dibuat oleh seeder di `backend/storage/app/overview/ar1-memory-guidelines-draft.md` |
| memories | `m1` workspace/semantic/active "Workspace coding conventions" (created by user, source `{type:user, actor user}`); `m2` project p1/semantic/active "Database: Postgres production, SQLite local dev" (user); `m3` project p2/semantic/active "Design tokens: 4px scale, status colors" (user); `m4` agent a1/episodic/active "Debugged session cookie CORS issue" (source execution e1, confidence 0.8, trust high); `m5` task t3/working/active "Working context for t3: decisions & progress" (source execution e1, `expires_at` +7 hari); `m6` project p1/semantic/**pending_review** "API v2 decisions: versioned routes" (proposed by a1 via `memory.propose`, source execution e1, trust medium) |
| approvals | `ap1` task_review (t3, e1, requested by a1, **pending**); `ap2` memory_review (m6, requested by a1, **pending**) |
| audit_logs | ±10 baris: task created/status, execution completed, memory created/approval requested (actor a1/e1) — memberi isi dashboard recent activity & `/memories/{id}/history` |

Checklist pertama-kali-boot: Dashboard 2 project / 3 agent / 6 memory / 2 pending approvals; kanban p1 menampilkan t1–t4 di 4 kolom berbeda; Approvals menampilkan ap1+ap2; Memory Explorer menampilkan semua type/scope/status termasuk pending m6.

---

## 5. Mock Agent Runner (internal, di `backend/app/Domain`)

### 5.1 Kontrak

- Endpoint pemicu: `POST /api/v1/tasks/{id}/execute`.
- Alur di controller: validasi pre-condition → buat row `executions` (`status=queued`, `model_resolution` hasil §3.10) → dispatch `ExecuteAgentJob(executionId)` → response 202.
- Dengan `QUEUE_CONNECTION=sync`, job dijalankan in-process sebelum response; dengan driver lain (`database`/`redis`) job tetap sama — titik tukar satu-satunya.
- Pre-condition (gagal = 409/422 sesuai §4.2): task status ∈ {`todo`, `in_progress`}; assignee = agent dengan `status=available`; tidak ada execution `queued/running` untuk task (TASK-015); `executionBudget.max_executions_per_task` (jika diisi) belum habis.

### 5.2 Komponen (binding, path & class)

```
backend/app/Domain/
  Runtime/
    RunnerContract.php      # interface: run(Execution $e): void
    MockRunner.php          # default (adapter "mock")
    CannedLlmClient.php     # chat(messages, model): CannedResponse (text deterministik)
    ModelResolver.php       # resolusi hierarchy §3.10
    ContextAssembler.php    # project/task/agent instructions + retrieved memories → prompt array
  Memory/
    MemoryService.php       # propose(), store(), update(), archive(), delete(), approve() — enforce provenance + versioning + policy
    MemorySearcher.php      # interface
    KeywordSearcher.php     # implementasi prototipe (skoring §5.4)
    MemoryScopeGuard.php    # filter scope/permission (MAC-03)
  Orchestration/
    ExecutionService.php    # create/cancel/recover + penulisan events
    ExecuteAgentJob.php     # job; memanggil runner sesuai agent.runtime
```

### 5.3 Sequence `MockRunner::run(execution)`

1. **task_received** — baca task + project + agent.
2. **agent_initialized** — payload `{ runtime: r1, agent, status: "working" }`; set `agents.status=working` (derived status, §6.4), execution `running`, `started_at`.
3. **Recovery check** (MAC-09): ambil working memory task scope (`scope=task`, `task_id`, `status=active`) → merge ke context (jika ada, lanjutkan; tidak membuat ulang).
4. **memory_retrieval** (tool `memory.search`, MAC-02):
   - query = task title + `memorySearchQuery` (jika diberikan).
   - kandidat = `KeywordSearcher` pada `title+content`, filter `MemoryScopeGuard` (§3.10: scope dari `agents.allowed_memory_scopes`, project hanya yang terikat, hanya `status=active`), di-limit `memory.retrieval.max_memories` **dan** `context_budget_tokens` (estimasi token = `ceil(strlen/4)`; potong daftar terpanjang hingga muat).
   - tulis `memory_retrievals` (rank+score), event `memory_retrieval` payload `{ query, retrieved: [{ memoryId, score }], budgetTokens, truncated: boolean }`.
   - Jika tool tidak ada di `tool_allowlist` → event `tool_rejected` + lanjut tanpa memory (AC-05).
5. **context_assembled** — payload `{ sections: ["project","task","agent_instructions","memory(n)"], promptTokens: int }`.
6. **llm_request** → **llm_response** (CannedLlmClient, deterministik):
   ```
   [mock-llm] Task "…": analyzed project context and {n} retrieved memories.
   Steps performed: {derived from task type, canned}. Deliverable: {artifact name}.
   ```
   usage `input_tokens` ≈ prompt tokens, `output_tokens` ≈ ceil(strlen/4) response. **Tidak ada LLM nyata, tidak ada network call** (§2.3).
7. **tool_requested → tool_executed (`artifact.submit`)**: tulis file ke `storage/app/overview/{executionId}-{slug}.md` (konten = response canned + context summary), row `artifacts`, event `artifact_submitted` payload `{ artifactId, name }`.
8. **memory.propose** (MAC-01/06):
   - satu memory `scope=task, type=working, status=active` ("progress + decision" dari execution) — event `memory_stored` payload `{ memoryId, scope: "task", status: "active" }`.
   - satu memory `scope=project, type=semantic, status=pending_review` (karena `workspace.memory_policy.require_review_for_shared_scopes=true`): provenance `source={type:"execution", refId: executionId, actorType:"agent", actorId, summary}`; buat `approvals` `memory_review` pending; event `approval_requested`.
   - Jika tool tidak di-allow → event `tool_rejected` + memory tidak dibuat.
9. **task_status_updated**: task → `in_review` (TASK-008), buat `approvals` `task_review` pending (AC-07), event payload `{ from: "in_progress", to: "in_review" }`; task sempat `in_progress` saat mulai step 1.
10. **execution_completed**: usage final (termasuk `retrieved_memory_count`), `finished_at`; agent status → `available` (jika task review reject → tetap `available`; gagal → `error`); audit log `execution.completed` (AC-10).

Jika ada exception di langkah mana pun: execution `failed` + `failure_reason` + event `execution_failed`/`error`; task kembali `in_progress` (RUN-010: state task tidak hilang).

### 5.4 Algoritma `KeywordSearcher` (deterministik)

```
score(memory) = 2 * tokenOverlap(query, memory.title)
              + tokenOverlap(query, memory.content)
              + 0.5 * recencyBoost            # 0.5 bila < 7 hari, 0 selainnya
              + memory.confidence * 0.2
tokenOverlap  = |tokens(q) ∩ tokens(x)| / |tokens(q)|   (lowercased, non-empty, len>2)
filter: scopeGuard §3.10, status=active, score > 0.05
rank by score desc → limit max_memories → cut by context_budget_tokens
```
Catatan: ranking sederhana (relevance+recency+confidence, MEM-035). Titik tukar `VectorSearcher` (pgvector) di `MemorySearcher` — skema `memories` tidak berubah.

### 5.5 Titik sambung runtime nyata (kontrak masa depan)

- `RunnerContract` adalah adapter boundary (PRD §6.8 Runtime Adapter). Runtime eksternal (OpenHands/dsb.) implementasi `RunnerContract` + registrasi `runtimes.adapter`; komunikasi via REST internal + event (§8.4), bukan in-process.
- `CannedLlmClient` di belakang `LlmClient` interface; adapter gateway nyata (OmniRoute/LiteLLM) mengganti implementasinya tanpa menyentuh runner (LLM-013/AC-11).
- `KeywordSearcher` → `VectorSearcher` (pgvector) tanpa ubah `memories`.
- `sync` queue → `database`/`redis` queue untuk durabilitas produksi (§2.2).

### 5.6 Traceability MAC (spot-check binding)

| MAC | Implementasi di prototipe |
|---|---|
| MAC-01 | Step 8: `MemoryService.propose` persist candidate dengan `source` (provenance) + actor + execution |
| MAC-02 | Step 4: retrieval sebelum fake LLM, hasilnya masuk context assembly |
| MAC-03 | `MemoryScopeGuard`: project memory hanya lewat `project_agents` + project task; agent memory hanya pemilik; dua project tak berhubungan tidak saling bocor |
| MAC-04 | Semua memory di DB (persisted); hapus runtime/restart tidak menghapusnya |
| MAC-05 | `PATCH /memories/{id}` + `supersede` menulis `memory_versions` |
| MAC-06 | Shared-scope proposal → `pending_review` + `approvals.memory_review`; hanya jadi `active` setelah approve |
| MAC-07 | `GET /memories/{id}` → source + versions + history (audit) tersedia |
| MAC-08 | `DELETE` enforce ownership/retention + hapus `memory_retrievals` (derived index); audit |
| MAC-09 | Step 3: working memory task scope dipersist + dimuat ulang saat eksekusi berikutnya |
| MAC-10 | Isi memory hanya data (canned text), tidak pernah dieksekusi/di-`eval`; permission hanya dari `agents.tool_allowlist` & scopes (§10.4) |

AC yang tertopang: AC-01/02/04/05/06/07/08(batasi)/09/10/11/13/14/15/16/17/18/19/21 — semua lewat kombinasi §3, §4, §5 di atas; AC-12 dibuktikan boot command §2.5.

---

## 6. Frontend Page Map

Stack binding (§2.5): React 18 + TypeScript (strict), Vite, **react-router-dom v6** (satu-satunya library UI), CSS Modules + 1 global CSS. Semua data via `frontend/src/api/client.ts` (fetch, `credentials: "include"`, unwrap `data`, throw `ApiError`).

| Route | Page / Komponen | API calls |
|---|---|---|
| `/` | **Dashboard** — `DashboardPage`: grid KPI (±6 card), running executions, pending approvals, recent activity | `GET /dashboard/stats` |
| `/projects` | **Projects** — `ProjectListPage`: tabel (status, priority, agent count, progress) + form `CreateProjectDialog` | `GET /projects`, `POST /projects` |
| `/projects/:id` | **ProjectDetailPage** dengan sub-tab: | |
| — `/projects/:id` | `ProjectOverviewTab`: description, objectives, status, progress %, members/agents, task distribution, project memory summary | `GET /projects/{id}`, `GET /projects/{id}/agents`, `GET /projects/{id}/memory` |
| — `/projects/:id/board` | `KanbanBoard` (per status, drag hanya untuk ubah status manual — call `PATCH /tasks/{id}`) | `GET /projects/{id}/tasks` (client group-by-status) |
| — `/projects/:id/tasks` | `TaskList` (filter type/status client-side) | `GET /projects/{id}/tasks` |
| `/tasks/:id` | **TaskDetailPage**: header (status badge, priority, assignee), description, tab **Comments** / **Artifacts** / **Memory** / **Executions**, panel **Execute** (tombol → hasil execution link) & panel **Model Override** (toggle `Override Agent Model` + gateway/model picker + indikator `resolvedSource` saat OFF), aksi **Review** (approve/reject + alasan) | `GET /tasks/{id}`, `GET /tasks/{id}/comments` (+`POST`), `GET /tasks/{id}/artifacts`, `GET /tasks/{id}/memory`, `GET /tasks/{id}/executions`, `POST /tasks/{id}/execute`, `POST /tasks/{id}/review`, `PATCH /tasks/{id}/model-override` |
| `/agents` | **AgentsPage**: card list (status badge, role, model) | `GET /agents` |
| `/agents/:id` | **AgentDetailPage**: identity + instructions + config (tools, budget, concurrency), panel **Model** (gateway/model + inheritance info), tab **Memory** (agent memories + retrieval config editor), tab **Executions** | `GET /agents/{id}`, `PATCH /agents/{id}`, `POST /agents/{id}/activate|deactivate`, `GET /agents/{id}/memory`, `PATCH /agents/{id}/memory-config`, `GET /agents/{id}/executions` |
| `/memory` | **MemoryExplorerPage**: filter bar (scope, type, status, agent, project, keyword `q`), tabel memory (badge status + scope + type + confidence), panel detail (content, **Provenance**: source/actor/execution link, **Versions**, **History**, aksi approve/reject/archive/supersede/delete), section pending review | `GET /memories`, `POST /memories/search` (untuk keyword), `GET /memories/{id}`, `POST /memories/{id}/approve|reject|archive|supersede`, `PATCH /memories/{id}`, `DELETE /memories/{id}`, `GET /memories/{id}/versions|sources|history` |
| `/executions` | **ExecutionsPage**: tabel execution (agent, task, status, model, usage ringkas, durasi) + filter | `GET /executions` |
| `/executions/:id` | **ExecutionDetailPage**: header state + agent + model resolution, `EventTimeline` (semua events berurut, collapsible payload), sections Tool calls / Retrieved memories / Artifacts / Usage; **polling 2 s** selama status `queued/running` (§1.3 realtime note) | `GET /executions/{id}`, `GET /executions/{id}/events?afterSequence=`, `GET /executions/{id}/artifacts`, `GET /executions/{id}/memories`, `GET /executions/{id}/model-resolution`, `POST /executions/{id}/cancel` |
| `/approvals` | **ApprovalsPage**: daftar pending (task_review + memory_review, menampilkan content+source sesuai APR-010 untuk memory), approve/reject + alasan | `GET /approvals`, `POST /approvals/{id}/approve|reject` |
| `/settings` | **SettingsPage**: tab **Gateways** (list, form create/edit, tombol Test + result, models), tab **Runtimes** (list + health), tab **Workspace** (edit defaults + memory policy) | `GET/POST/PATCH /gateways`, `POST /gateways/{id}/test`, `GET /gateways/{id}/models`, `GET/POST /runtimes`, `POST /runtimes/{id}/health`, `GET/PATCH /workspaces/{id}` |

Sidebar (global nav prototipe): Dashboard, Projects, Agents, Memory, Executions, Approvals (badge pending), Settings. Nav tidak memuat Workflows/Registry/Skills/Activity-standalone (out of scope §1.3; activity ada di dashboard).

### 6.1 Aturan desain (PRD §14.1) & arah visual CSS Modules

Prinsip diulang dari §14.1: **clarity, low cognitive load, hierarchy, consistency, navigasi cepat, observability** — dan eksplisit: hindari metric overload. Rule binding:
- Maksimal **6 KPI card** di dashboard, angka besar + label, tanpa chart (fase berikutnya).
- **Satu warna per state status**, dipakai konsisten di semua badge/kolom:

| State | Warna (badge: text on 10% bg + solid dot) |
|---|---|
| `todo` / `candidate` / `unknown` | `#6B7280` (gray-600) |
| `in_progress` / `running` | `#2563EB` (blue-600) |
| `pending` / `pending_review` / `waiting` | `#B45309` (amber-700) |
| `blocked` / `failed` / `error` / `rejected` | `#DC2626` (red-600) |
| `in_review` / `approved` | `#7C3AED` (violet-600) |
| `done` / `completed` / `active` / `connected` / `healthy` | `#16A34A` (green-600) |
| `cancelled` / `archived` / `superseded` / `expired` / `offline` / `disabled` | `#9CA3AF` (gray-400) |

- Tipografi: system font stack (`"Segoe UI", system-ui, ...`), base 14px, heading 16/18/20px, tabel 13px, mono (`ui-monospace, Consolas`) untuk id/model/payload JSON.
- Spasi: skala 4px (4/8/12/16/24/32); content max-width 1200px; sidebar 240px; radius 8px card / 6px kontrol.
- Warna netral: bg page `#F9FAFB`, permukaan white, border `#E5E7EB`, teks utama `#111827`, teks sekunder `#6B7280`. Tanpa shadow kecuali hover card (elevation 1).
- Komponen bersama di `frontend/src/components/`: `AppShell` (sidebar+topbar), `StatusBadge` (pemilik peta warna di atas), `DataTable`, `KanbanBoard`, `EventTimeline`, `EmptyState`, `ConfirmDialog`. Tiap komponen punya `.module.css` sendiri; tokens (warna/spasi/font) di `styles/tokens.css` via CSS custom properties agar "satu warna per state" tidak pernah terduplikasi.

---

## 7. Repo Layout & Boot

```
OverView/
├─ PRD.md
├─ README.md
├─ ARCHITECTURE.md            # dokumen ini
├─ docker-compose.yml         # OPSIONAL (ADR-4): postgres:16 + redis:7 untuk dev dengan Docker
├─ backend/                   # Laravel (skeleton default, PHP 8.2+, tanpa package tambahan)
│  ├─ .env                    # committed defaults: sqlite + sync + file (table §2.5)
│  ├─ app/
│  │  ├─ Enums/               # TaskType, TaskStatus, AgentStatus, ExecutionStatus, MemoryScope, MemoryType, MemoryStatus, …
│  │  ├─ Models/              # satu model Eloquent per tabel §3 (User, Workspace, …, Memory, MemoryVersion, MemoryRetrieval, Approval, AuditLog)
│  │  ├─ Domain/
│  │  │  ├─ Runtime/          # RunnerContract, MockRunner, CannedLlmClient, ModelResolver, ContextAssembler (§5.2)
│  │  │  ├─ Memory/           # MemoryService, MemorySearcher (interface), KeywordSearcher, MemoryScopeGuard
│  │  │  └─ Orchestration/   # ExecutionService, ExecuteAgentJob
│  │  ├─ Http/
│  │  │  ├─ Controllers/Api/V1/   # Auth, Workspaces, Dashboard, Projects, Tasks, Agents, Gateways, Runtimes, Executions, Artifacts, Memories, Approvals, Audit
│  │  │  └─ ApiErrorFormatter.php # shape error §4.1 di semua exception handler API
│  └─ database/
│     ├─ migrations/         # 1 file migrasi per tabel §3 (urutan FK), portabel SQLite+Postgres
│     └─ seeders/            # DatabaseSeeder → DemoSeeder (§4.3, deterministik, termasuk file artifact seed)
└─ frontend/                 # Vite + React + TS
   ├─ package.json            # deps: react, react-dom, react-router-dom, vite, typescript, @types/*
   ├─ vite.config.ts          # proxy /api → http://localhost:8000
   └─ src/
      ├─ main.tsx  App.tsx   # AppShell + Routes (§6)
      ├─ api/
      │  ├─ client.ts        # fetch + credentials + ApiError
      │  ├─ types.ts         # semua type §4.1 (copy source-of-truth)
      │  └─ endpoints.ts     # satu fungsi per route §4.2
      ├─ components/         # AppShell, StatusBadge, DataTable, KanbanBoard, EventTimeline, EmptyState, ConfirmDialog
      ├─ pages/              # DashboardPage, ProjectListPage, ProjectDetailPage(+tabs), TaskDetailPage, AgentsPage, AgentDetailPage, MemoryExplorerPage, ExecutionsPage, ExecutionDetailPage, ApprovalsPage, SettingsPage
      ├─ styles/             # global.css, tokens.css (CSS custom properties §6.1)
      └─ hooks/              # useApi, usePolling (2 s), useTaskExecution
```

Konvensi binding: identifier English; komentar kode boleh English; migrasi portabel (§2.1); tidak ada UI framework/styling lib (§8.3 PRD); controller tipis (validasi + delegasi Domain); semua mutasi sensitif memanggil `AuditLog::record` (§10.5, AC-10); semua query resource memakai `workspace_id` milik session (AC-09).

### 7.1 Boot (harus jalan, verbatim)

```powershell
# satu kali
cd backend && composer install
php artisan migrate --seed
php artisan serve          # http://localhost:8000

cd frontend && npm install
npm run dev                # http://localhost:5173 → login demo@overview.local / demo
```

Verifikasi sukses prototipe = alur §1.1 langkah 1–8 + checklist §4.3.

---

*Akhir dokumen. Kontrak ini mengikat implementasi backend/frontend; deviasi harus dicatat di `docs/deviations.md` (belum ada, dibuat saat dibutuhkan).*
