<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET,POST,PATCH,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

$allowedStages = ['applied', 'screening', 'interviewing', 'offered', 'hired', 'rejected'];

function sendJson(array $payload, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function errorResponse(string $message, int $status = 400): void
{
  sendJson([
    'success' => false,
    'data' => [],
    'error' => $message,
  ], $status);
}

function successResponse(array $data, int $status = 200): void
{
  sendJson([
    'success' => true,
    'data' => $data,
    'error' => '',
  ], $status);
}

function getPdo(): PDO
{
  return new PDO(
    'mysql:host=db;dbname=talentstream;charset=utf8mb4',
    'root',
    'secret',
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
}

function readJsonBody(): array
{
  $rawBody = file_get_contents('php://input');

  if ($rawBody === false || trim($rawBody) === '') {
    return [];
  }

  try {
    $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $exception) {
    errorResponse('Invalid JSON body.', 400);
  }

  return is_array($decoded) ? $decoded : [];
}

function isPositiveInt(mixed $value): bool
{
  return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int) $value > 0);
}

function normalizeOptionalString(mixed $value): ?string
{
  if (!is_string($value)) {
    return null;
  }

  $trimmed = trim($value);

  return $trimmed === '' ? null : $trimmed;
}

function normalizeOptionalPositiveInt(mixed $value): ?int
{
  if ($value === null || $value === '') {
    return null;
  }

  if (is_int($value) && $value >= 0) {
    return $value;
  }

  if (is_string($value) && ctype_digit($value)) {
    return (int) $value;
  }

  return null;
}

function normalizeJob(array $row): array
{
  return [
    'id' => (int) $row['id'],
    'title' => $row['title'],
    'department' => $row['department'],
    'required_skills' => $row['required_skills'] ?? null ,
    'min_years_experience' => isset($row['min_years_experience']) ? (int) $row['min_years_experience'] : null,
    'description' => $row['description'],
    'status' => $row['status'],
    'created_at' => $row['created_at'],
  ];
}

function normalizeCandidate(array $row): array
{
  return [
    'id' => (int) $row['id'],
    'full_name' => $row['full_name'],
    'email' => $row['email'],
    'resume_path' => $row['resume_path'],
    'created_at' => $row['created_at'],
  ];
}

function normalizeApplication(array $row): array
{
  return [
    'id' => (int) $row['id'],
    'stage' => $row['stage'],
    'applied_at' => $row['applied_at'],
    'job' => [
      'id' => (int) $row['job_id'],
      'title' => $row['job_title'],
      'department' => $row['job_department'],
      'description' => $row['job_description'],
      'status' => $row['job_status'],
      'created_at' => $row['job_created_at'],
    ],
    'candidate' => [
      'id' => (int) $row['candidate_id'],
      'full_name' => $row['candidate_full_name'],
      'email' => $row['candidate_email'],
      'resume_path' => $row['candidate_resume_path'],
      'created_at' => $row['candidate_created_at'],
    ],
  ];
}

function normalizeNote(array $row): array
{
  return [
    'id' => (int) $row['id'],
    'application_id' => (int) $row['application_id'],
    'body' => $row['body'],
    'created_at' => $row['created_at'],
  ];
}

function fetchJobById(PDO $pdo, int $jobId): ?array
{
  $statement = $pdo->prepare('SELECT id, title, department, description, status, created_at FROM jobs WHERE id = :id');
  $statement->execute(['id' => $jobId]);
  $row = $statement->fetch();

  return $row === false ? null : normalizeJob($row);
}

function fetchCandidateById(PDO $pdo, int $candidateId): ?array
{
  $statement = $pdo->prepare('SELECT id, full_name, email, resume_path, created_at FROM candidates WHERE id = :id');
  $statement->execute(['id' => $candidateId]);
  $row = $statement->fetch();

  return $row === false ? null : normalizeCandidate($row);
}

function fetchApplicationById(PDO $pdo, int $applicationId): ?array
{
  $statement = $pdo->prepare(
    'SELECT
      a.id,
      a.stage,
      a.applied_at,
      a.job_id,
      a.candidate_id,
      j.title AS job_title,
      j.department AS job_department,
      j.description AS job_description,
      j.status AS job_status,
      j.created_at AS job_created_at,
      c.full_name AS candidate_full_name,
      c.email AS candidate_email,
      c.resume_path AS candidate_resume_path,
      c.created_at AS candidate_created_at
     FROM applications a
     INNER JOIN jobs j ON j.id = a.job_id
     INNER JOIN candidates c ON c.id = a.candidate_id
     WHERE a.id = :id'
  );
  $statement->execute(['id' => $applicationId]);
  $row = $statement->fetch();

  return $row === false ? null : normalizeApplication($row);
}

function fetchNotesForApplication(PDO $pdo, int $applicationId): array
{
  $statement = $pdo->prepare(
    'SELECT id, application_id, body, created_at
     FROM notes
     WHERE application_id = :application_id
     ORDER BY created_at ASC, id ASC'
  );
  $statement->execute(['application_id' => $applicationId]);

  return array_map('normalizeNote', $statement->fetchAll());
}

function validateRequiredString(array $payload, string $fieldName, int $status = 422): string
{
  $value = $payload[$fieldName] ?? null;

  if (!is_string($value)) {
    errorResponse(sprintf('The %s field is required.', $fieldName), $status);
  }

  $trimmed = trim($value);

  if ($trimmed === '') {
    errorResponse(sprintf('The %s field is required.', $fieldName), $status);
  }

  return $trimmed;
}

function validateRequiredEmail(array $payload, string $fieldName, int $status = 422): string
{
  $email = validateRequiredString($payload, $fieldName, $status);

  if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    errorResponse('The email field must be a valid email address.', $status);
  }

  return $email;
}

function validateAllowedStage(array $payload, array $allowedStages): string
{
  $stage = validateRequiredString($payload, 'stage');

  if (!in_array($stage, $allowedStages, true)) {
    errorResponse('Invalid stage value.', 422);
  }

  return $stage;
}

function routeMethodNotAllowed(): void
{
  errorResponse('Method not allowed.', 405);
}

set_exception_handler(static function (Throwable $exception): void {
  errorResponse('Server error.', 500);
});

$pdo = getPdo();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
  if ($path === '/api/jobs') {
    if ($method === 'GET') {
      $statement = $pdo->query('SELECT id, title, department, required_skills, min_years_experience, description, status, created_at FROM jobs ORDER BY created_at DESC, id DESC');
      successResponse($statement->fetchAll());
    }

    if ($method === 'POST') {
      $payload = readJsonBody();
      $title = validateRequiredString($payload, 'title');
      $department = normalizeOptionalString($payload['department'] ?? null);
      $requiredSkills = normalizeOptionalString($payload['required_skills'] ?? null);
      $description = normalizeOptionalString($payload['description'] ?? null);
      $minYearsExperienceInput = $payload['min_years_experience'] ?? null;

      if ($minYearsExperienceInput === null || $minYearsExperienceInput === '') {
        $minYearsExperience = 0;
      } else {
        $minYearsExperience = normalizeOptionalPositiveInt($minYearsExperienceInput);

        if ($minYearsExperience === null) {
          errorResponse('The min_years_experience field must be a non-negative integer.', 422);
        }
      }

      $status = validateRequiredString($payload, 'status');

      if (!in_array($status, ['open', 'closed'], true)) {
        errorResponse('The status field must be either open or closed.', 422);
      }

      $statement = $pdo->prepare(
        'INSERT INTO jobs (title, department, required_skills, min_years_experience, description, status)
         VALUES (:title, :department, :required_skills, :min_years_experience, :description, :status)'
      );
      $statement->execute([
        'title' => $title,
        'department' => $department,
        'required_skills' => $requiredSkills,
        'min_years_experience' => $minYearsExperience,
        'description' => $description,
        'status' => $status,
      ]);

      $job = fetchJobById($pdo, (int) $pdo->lastInsertId());

      if ($job === null) {
        errorResponse('Unable to create job.', 500);
      }

      successResponse([$job], 201);
    }

    routeMethodNotAllowed();
  }

  if ($method === 'DELETE' && preg_match('#^/api/jobs/(\d+)$#', $path, $matches) === 1) {
    $jobId = (int) $matches[1];

    if (fetchJobById($pdo, $jobId) === null) {
      errorResponse('Not found.', 404);
    }

    $statement = $pdo->prepare('DELETE FROM jobs WHERE id = :id');
    $statement->execute(['id' => $jobId]);

    successResponse([]);
  }

  if ($method === 'PATCH' && preg_match('#^/api/jobs/(\d+)$#', $path, $matches) === 1) {
    $jobId = (int) $matches[1];
    $currentJob = fetchJobById($pdo, $jobId);

    if ($currentJob === null) {
      errorResponse('Not found.', 404);
    }

    $payload = readJsonBody();
    $updates = [];
    $parameters = ['id' => $jobId];

    $title = $payload['title'] ?? null;
    $department = $payload['department'] ?? null;
    $requiredSkills = $payload['required_skills'] ?? null;
    $minYearsExperienceInput = $payload['min_years_experience'] ?? null;
    $description = $payload['description'] ?? null;
    $status = $payload['status'] ?? null;

    if (array_key_exists('title', $payload)) {
      if (!is_string($title) || trim($title) === '') {
        errorResponse('The title field is required.', 422);
      }

      $updates[] = 'title = :title';
      $parameters['title'] = trim($title);
    }

    if (array_key_exists('department', $payload)) {
      $updates[] = 'department = :department';
      $parameters['department'] = normalizeOptionalString($department);
    }

    if (array_key_exists('required_skills', $payload)) {
      $updates[] = 'required_skills = :required_skills';
      $parameters['required_skills'] = normalizeOptionalString($requiredSkills);
    }

    if (array_key_exists('min_years_experience', $payload)) {
      if ($minYearsExperienceInput === null || $minYearsExperienceInput === '') {
        errorResponse('The min_years_experience field must be a non-negative integer.', 422);
      }

      $minYearsExperience = normalizeOptionalPositiveInt($minYearsExperienceInput);

      if ($minYearsExperience === null) {
        errorResponse('The min_years_experience field must be a non-negative integer.', 422);
      }

      $updates[] = 'min_years_experience = :min_years_experience';
      $parameters['min_years_experience'] = $minYearsExperience;
    }

    if (array_key_exists('description', $payload)) {
      $updates[] = 'description = :description';
      $parameters['description'] = normalizeOptionalString($description);
    }

    if (array_key_exists('status', $payload)) {
      if (!is_string($status) || trim($status) === '') {
        errorResponse('The status field must be either open or closed.', 422);
      }

      $status = trim($status);

      if (!in_array($status, ['open', 'closed'], true)) {
        errorResponse('The status field must be either open or closed.', 422);
      }

      $updates[] = 'status = :status';
      $parameters['status'] = $status;
    }

    if (count($updates) === 0) {
      errorResponse('No valid fields to update', 400);
    }

    $statement = $pdo->prepare(
      'UPDATE jobs
       SET ' . implode(', ', $updates) . '
       WHERE id = :id'
    );
    $statement->execute($parameters);

    $updatedJob = fetchJobById($pdo, $jobId);

    if ($updatedJob === null) {
      errorResponse('Not found.', 404);
    }

    sendJson([
      'success' => true,
      'data' => $updatedJob,
      'error' => '',
    ]);
  }

  if ($path === '/api/candidates') {
    if ($method === 'GET') {
      $statement = $pdo->query('SELECT id, full_name, email, resume_path, created_at FROM candidates ORDER BY created_at DESC, id DESC');
      successResponse($statement->fetchAll());
    }

    if ($method === 'POST') {
      $payload = readJsonBody();
      $fullName = validateRequiredString($payload, 'full_name');
      $email = validateRequiredEmail($payload, 'email');

      $statement = $pdo->prepare(
        'INSERT INTO candidates (full_name, email)
         VALUES (:full_name, :email)'
      );
      $statement->execute([
        'full_name' => $fullName,
        'email' => $email,
      ]);

      $candidate = fetchCandidateById($pdo, (int) $pdo->lastInsertId());

      if ($candidate === null) {
        errorResponse('Unable to create candidate.', 500);
      }

      successResponse([$candidate], 201);
    }

    routeMethodNotAllowed();
  }

  if ($path === '/api/applications') {
    if ($method === 'GET') {
      $statement = $pdo->query(
        'SELECT
          a.id,
          a.stage,
          a.applied_at,
          a.job_id,
          a.candidate_id,
          j.title AS job_title,
          j.department AS job_department,
          j.description AS job_description,
          j.status AS job_status,
          j.created_at AS job_created_at,
          c.full_name AS candidate_full_name,
          c.email AS candidate_email,
          c.resume_path AS candidate_resume_path,
          c.created_at AS candidate_created_at
         FROM applications a
         INNER JOIN jobs j ON j.id = a.job_id
         INNER JOIN candidates c ON c.id = a.candidate_id
         ORDER BY a.applied_at DESC, a.id DESC'
      );

      $applications = [];

      foreach ($statement->fetchAll() as $row) {
        $applications[] = normalizeApplication($row);
      }

      successResponse($applications);
    }

    if ($method === 'POST') {
      $payload = readJsonBody();
      $jobIdInput = $payload['job_id'] ?? null;
      $candidateIdInput = $payload['candidate_id'] ?? null;

      if (!isPositiveInt($jobIdInput)) {
        errorResponse('The job_id field must be a positive integer.', 422);
      }

      if (!isPositiveInt($candidateIdInput)) {
        errorResponse('The candidate_id field must be a positive integer.', 422);
      }

      $jobId = (int) $jobIdInput;
      $candidateId = (int) $candidateIdInput;

      if (fetchJobById($pdo, $jobId) === null) {
        errorResponse('Job not found.', 404);
      }

      if (fetchCandidateById($pdo, $candidateId) === null) {
        errorResponse('Candidate not found.', 404);
      }

      $statement = $pdo->prepare(
        'INSERT INTO applications (job_id, candidate_id)
         VALUES (:job_id, :candidate_id)'
      );
      $statement->execute([
        'job_id' => $jobId,
        'candidate_id' => $candidateId,
      ]);

      $application = fetchApplicationById($pdo, (int) $pdo->lastInsertId());

      if ($application === null) {
        errorResponse('Unable to create application.', 500);
      }

      successResponse([$application], 201);
    }

    routeMethodNotAllowed();
  }

  if ($method === 'PATCH' && preg_match('#^/api/applications/(\d+)$#', $path, $matches) === 1) {
    $applicationId = (int) $matches[1];
    $payload = readJsonBody();
    $stage = validateAllowedStage($payload, $allowedStages);

    if (fetchApplicationById($pdo, $applicationId) === null) {
      errorResponse('Application not found.', 404);
    }

    $statement = $pdo->prepare('UPDATE applications SET stage = :stage WHERE id = :id');
    $statement->execute([
      'stage' => $stage,
      'id' => $applicationId,
    ]);

    $updatedApplication = fetchApplicationById($pdo, $applicationId);

    if ($updatedApplication === null) {
      errorResponse('Application not found.', 404);
    }

    successResponse([$updatedApplication]);
  }

  if ($method === 'DELETE' && preg_match('#^/api/applications/(\d+)$#', $path, $matches) === 1) {
    $applicationId = (int) $matches[1];

    if (fetchApplicationById($pdo, $applicationId) === null) {
      errorResponse('Not found.', 404);
    }

    $statement = $pdo->prepare('DELETE FROM applications WHERE id = :id');
    $statement->execute(['id' => $applicationId]);

    successResponse([]);
  }

  if ($method === 'GET' && preg_match('#^/api/notes/(\d+)$#', $path, $matches) === 1) {
    $applicationId = (int) $matches[1];

    if (fetchApplicationById($pdo, $applicationId) === null) {
      errorResponse('Application not found.', 404);
    }

    successResponse(fetchNotesForApplication($pdo, $applicationId));
  }

  if ($method === 'DELETE' && preg_match('#^/api/notes/(\d+)$#', $path, $matches) === 1) {
    $noteId = (int) $matches[1];

    $statement = $pdo->prepare('SELECT id FROM notes WHERE id = :id');
    $statement->execute(['id' => $noteId]);

    if ($statement->fetch() === false) {
      errorResponse('Not found.', 404);
    }

    $deleteStatement = $pdo->prepare('DELETE FROM notes WHERE id = :id');
    $deleteStatement->execute(['id' => $noteId]);

    successResponse([]);
  }

  if ($path === '/api/notes' && $method === 'POST') {
    $payload = readJsonBody();
    $applicationIdInput = $payload['application_id'] ?? null;

    if (!isPositiveInt($applicationIdInput)) {
      errorResponse('The application_id field must be a positive integer.', 422);
    }

    $applicationId = (int) $applicationIdInput;
    $body = validateRequiredString($payload, 'body');

    if (fetchApplicationById($pdo, $applicationId) === null) {
      errorResponse('Application not found.', 404);
    }

    $statement = $pdo->prepare(
      'INSERT INTO notes (application_id, body)
       VALUES (:application_id, :body)'
    );
    $statement->execute([
      'application_id' => $applicationId,
      'body' => $body,
    ]);

    $createdNote = $pdo->prepare(
      'SELECT id, application_id, body, created_at
       FROM notes
       WHERE id = :id'
    );
    $createdNote->execute(['id' => (int) $pdo->lastInsertId()]);

    $row = $createdNote->fetch();

    if ($row === false) {
      errorResponse('Unable to create note.', 500);
    }

    successResponse([normalizeNote($row)], 201);
  }

  if ($path === '/api/stats' && $method === 'GET') {
    $statement = $pdo->prepare(
      'SELECT
         (SELECT COUNT(*) FROM jobs) AS total_jobs,
         (SELECT COUNT(*) FROM applications) AS total_applications,
         (SELECT COUNT(*) FROM applications WHERE stage IN ("screening", "interviewing")) AS in_progress,
         (SELECT COUNT(*) FROM applications WHERE stage = "hired") AS hired'
    );
    $statement->execute();
    $row = $statement->fetch();

    if ($row === false) {
      errorResponse('Unable to load stats.', 500);
    }

    successResponse([
      'total_jobs' => (int) $row['total_jobs'],
      'total_applications' => (int) $row['total_applications'],
      'in_progress' => (int) $row['in_progress'],
      'hired' => (int) $row['hired'],
    ]);
  }

  errorResponse('Not found.', 404);
} catch (Throwable $exception) {
  errorResponse('Server error.', 500);
}