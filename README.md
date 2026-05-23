# TalentStream ATS

A full-stack Applicant Tracking System (ATS) built with Next.js, PHP, and MySQL. Manage job postings, candidates, and applications through a drag-and-drop Kanban pipeline.

---

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Frontend](#frontend)
- [Backend](#backend)
- [Database](#database)
- [API Reference](#api-reference)

---

## Overview

TalentStream ATS provides three main views:

- **Dashboard** — Kanban board with drag-and-drop cards across six hiring stages (Applied → Screening → Interviewing → Offered → Hired → Rejected). Supports filtering by job title, required skills, and minimum years of experience. Click any card to open a side drawer with candidate details and notes.
- **Jobs** — Create and browse job postings with title, department, required skills, experience, description, and open/closed status.
- **Candidates** — Add candidates and submit them to open job roles directly from the list.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | Next.js 16, React 19, TypeScript, Tailwind CSS 4, SWR, dnd-kit |
| Backend | PHP 8.2 (Apache), PDO + MySQL |
| Database | MySQL 8 |
| Infrastructure | Docker + Docker Compose |

---

## Project Structure

```
├── backend/
│   ├── public/
│   │   └── index.php          # Single-file PHP API
│   └── Dockerfile
├── database/
│   ├── init-db.sh             # Runs schema on first container start
│   └── schema.sql             # Table definitions (reference)
├── frontend/
│   ├── app/
│   │   ├── api/applications/  # Next.js route handlers (proxy to PHP)
│   │   ├── candidates/        # Candidates page
│   │   ├── jobs/              # Jobs page
│   │   ├── layout.tsx         # Root layout with nav
│   │   └── page.tsx           # Dashboard / Kanban board
│   ├── components/
│   │   ├── BackButton.tsx
│   │   └── top-nav.tsx
│   └── ...config files
└── docker-compose.yml
```

---

## Getting Started

### Prerequisites

- [Docker](https://www.docker.com/) and Docker Compose

### Run the full stack

```bash
docker compose up --build
```

| Service | URL |
|---|---|
| Frontend (Next.js) | http://localhost:3000 |
| Backend (PHP API) | http://localhost:8080 |
| MySQL | localhost:3306 |

The database is initialized automatically on first run via `database/init-db.sh`.

### Stop

```bash
docker compose down
```

To also remove the database volume:

```bash
docker compose down -v
```

---

## Frontend

Located in `frontend/`. A Next.js 16 app using the App Router.

### Local development (without Docker)

```bash
cd frontend
npm install
npm run dev
```

Open http://localhost:3000.

> The frontend expects the PHP API at `http://localhost:8080`. When running outside Docker, start the backend separately or update the `BACKEND_BASE_URL` in `app/api/applications/route.ts`.

### Key pages

| Route | Description |
|---|---|
| `/` | Kanban board — drag cards between stages, filter applications, view candidate details and add notes |
| `/jobs` | Job postings — create new jobs, view status summary |
| `/candidates` | Candidates list — add new candidates, apply them to open jobs |

### Next.js route handlers

`app/api/applications/` proxies requests from the browser to the PHP backend, keeping the backend off direct browser access:

- `GET /api/applications` — fetch all applications (supports `?job_title=`, `?skills=`, `?min_years=` query params)
- `PATCH /api/applications/[id]` — update an application's stage

### Scripts

```bash
npm run dev      # Development server
npm run build    # Production build
npm run start    # Start production server
npm run lint     # ESLint
```

---

## Backend

Located in `backend/`. A single PHP 8.2 file (`public/index.php`) that handles all API routing manually using `parse_url` and `preg_match`.

- Connects to MySQL via PDO
- Returns JSON with a consistent envelope: `{ success, data, error }`
- CORS headers allow requests from `http://localhost:3000`

### Running with Docker

The backend runs automatically as part of `docker compose up`. It is served by Apache on port 8080.

### Dockerfile

```dockerfile
FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite
```

The `public/` directory is mapped as the Apache document root.

---

## Database

Located in `database/`. MySQL 8 with four tables.

### Schema

```
jobs
  id, title, department, required_skills, min_years_experience,
  description, status (open|closed), created_at

candidates
  id, full_name, email, resume_path, created_at

applications
  id, job_id → jobs, candidate_id → candidates,
  stage (applied|screening|interviewing|offered|hired|rejected),
  applied_at

notes
  id, application_id → applications, body, created_at
```

All foreign keys cascade on delete.

### Initialization

`database/init-db.sh` is mounted into the MySQL container's `/docker-entrypoint-initdb.d/` directory and runs automatically when the volume is empty (i.e., on first start).

`database/schema.sql` is the reference schema using standard SQL syntax (useful for non-MySQL databases or manual inspection).

---

## API Reference

All endpoints return `{ success: bool, data: array|object, error: string }`.

### Jobs

| Method | Path | Description |
|---|---|---|
| GET | `/api/jobs` | List all jobs |
| POST | `/api/jobs` | Create a job |
| PATCH | `/api/jobs/:id` | Update a job |
| DELETE | `/api/jobs/:id` | Delete a job |

**POST/PATCH body fields:** `title` (required), `department`, `required_skills`, `min_years_experience`, `description`, `status` (open\|closed)

### Candidates

| Method | Path | Description |
|---|---|---|
| GET | `/api/candidates` | List all candidates |
| POST | `/api/candidates` | Create a candidate |

**POST body fields:** `full_name` (required), `email` (required, unique)

### Applications

| Method | Path | Description |
|---|---|---|
| GET | `/api/applications` | List all applications (with job + candidate data) |
| POST | `/api/applications` | Create an application |
| PATCH | `/api/applications/:id` | Update stage |
| DELETE | `/api/applications/:id` | Delete an application |

**GET query params:** `job_title`, `skills`, `min_years`
**POST body:** `job_id`, `candidate_id`
**PATCH body:** `stage` (applied\|screening\|interviewing\|offered\|hired\|rejected)

### Notes

| Method | Path | Description |
|---|---|---|
| GET | `/api/notes/:application_id` | List notes for an application |
| POST | `/api/notes` | Add a note |
| DELETE | `/api/notes/:id` | Delete a note |

**POST body:** `application_id`, `body`

### Stats

| Method | Path | Description |
|---|---|---|
| GET | `/api/stats` | Returns `total_jobs`, `total_applications`, `in_progress`, `hired` |
