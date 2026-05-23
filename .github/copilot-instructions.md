# TalentStream – Copilot context

## Stack
- Frontend: Next.js 14 (App Router), TypeScript, Tailwind CSS, dnd-kit (drag-and-drop)
- Backend: PHP 8.2, PDO with prepared statements (no raw queries ever)
- DB: MySQL 8, running in Docker
- API: REST JSON, base URL http://localhost:8080/api

## Key domain terms
- Stage: one of [applied, screening, interviewing, offered, hired, rejected]
- Application: a candidate's submission to a specific job (has a stage, notes, timestamps)
- The Kanban board shows applications grouped by stage; cards are draggable between columns

## Conventions
- PHP: always use PDO prepared statements; return JSON with {success, data, error}
- Next.js: fetch from /api/* using Next.js Route Handlers as a proxy to the PHP backend
- All dates stored as UTC in MySQL; display in local time on the frontend
- No ORMs on the PHP side; write raw SQL with PDO