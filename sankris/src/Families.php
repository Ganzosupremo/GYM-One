<?php
declare(strict_types=1);

namespace Sankris;

/** Phase 1: families, guardians (responsables) and students. */
final class Families
{
    private Db $db;
    private Audit $audit;

    private const FAMILY_FIELDS = ['name', 'status', 'tags', 'notes', 'external_id'];
    private const GUARDIAN_FIELDS = ['first_name', 'last_name', 'relationship', 'email', 'phone', 'is_payer', 'is_emergency_contact', 'tax_id', 'billing_name', 'autopay', 'external_id'];
    private const STUDENT_FIELDS = ['first_name', 'last_name', 'birthdate', 'gender', 'program_id', 'level', 'status', 'tags', 'notes', 'medical_notes', 'emergency_contact_name', 'emergency_contact_phone', 'photo_consent', 'external_id'];

    public function __construct(Db $db, Audit $audit)
    {
        $this->db = $db;
        $this->audit = $audit;
    }

    public function family(int $id): ?array
    {
        return $this->db->one('SELECT * FROM sk_families WHERE id = ?', [$id]);
    }

    public function guardian(int $id): ?array
    {
        return $this->db->one('SELECT * FROM sk_guardians WHERE id = ?', [$id]);
    }

    public function student(int $id): ?array
    {
        return $this->db->one('SELECT s.*, p.name AS program_name FROM sk_students s LEFT JOIN programs p ON p.id = s.program_id WHERE s.id = ?', [$id]);
    }

    public function guardians(int $familyId): array
    {
        return $this->db->all('SELECT * FROM sk_guardians WHERE family_id = ? ORDER BY is_payer DESC, id', [$familyId]);
    }

    public function students(int $familyId): array
    {
        return $this->db->all('SELECT s.*, p.name AS program_name FROM sk_students s LEFT JOIN programs p ON p.id = s.program_id WHERE s.family_id = ? ORDER BY s.first_name', [$familyId]);
    }

    public function payer(int $familyId): ?array
    {
        return $this->db->one('SELECT * FROM sk_guardians WHERE family_id = ? ORDER BY is_payer DESC, id LIMIT 1', [$familyId]);
    }

    public function search(string $q = '', string $status = '', int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(f.name LIKE ? OR EXISTS (SELECT 1 FROM sk_guardians g WHERE g.family_id = f.id AND (CONCAT(g.first_name, " ", g.last_name) LIKE ? OR g.email LIKE ? OR g.phone LIKE ?))
                       OR EXISTS (SELECT 1 FROM sk_students s WHERE s.family_id = f.id AND CONCAT(s.first_name, " ", s.last_name) LIKE ?))';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'f.status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT f.*,
                  (SELECT COUNT(*) FROM sk_students s WHERE s.family_id = f.id) AS students_count,
                  (SELECT CONCAT(g.first_name, " ", g.last_name) FROM sk_guardians g WHERE g.family_id = f.id ORDER BY g.is_payer DESC, g.id LIMIT 1) AS payer_name
                FROM sk_families f WHERE ' . implode(' AND ', $where) . ' ORDER BY f.name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return $this->db->all($sql, $params);
    }

    public function createFamily(array $data): int
    {
        $data = self::pick($data, self::FAMILY_FIELDS);
        self::require($data, ['name']);
        $data['status'] = $data['status'] ?? 'active';
        $id = $this->db->insert('sk_families', $data);
        $this->audit->log('family', $id, 'create', null, $data);
        return $id;
    }

    public function updateFamily(int $id, array $data): void
    {
        $before = $this->family($id) ?? throw new \RuntimeException('Familia no encontrada');
        $data = self::pick($data, self::FAMILY_FIELDS);
        if (array_key_exists('name', $data)) {
            self::require($data, ['name']);
        }
        $this->db->update('sk_families', $data, 'id = ?', [$id]);
        $this->audit->log('family', $id, 'update', $before, $data);
    }

    public function addGuardian(int $familyId, array $data): int
    {
        $this->family($familyId) ?? throw new \RuntimeException('Familia no encontrada');
        $data = self::pick($data, self::GUARDIAN_FIELDS);
        self::require($data, ['first_name', 'last_name']);
        self::validateEmail($data['email'] ?? null);
        return $this->db->transaction(function () use ($familyId, $data) {
            // The first guardian of a family is its payer by default
            $hasPayer = (int) $this->db->value('SELECT COUNT(*) FROM sk_guardians WHERE family_id = ? AND is_payer = 1', [$familyId]) > 0;
            if (!$hasPayer && !isset($data['is_payer'])) {
                $data['is_payer'] = 1;
            }
            if (!empty($data['is_payer'])) {
                $this->db->run('UPDATE sk_guardians SET is_payer = 0 WHERE family_id = ?', [$familyId]);
            }
            $data['family_id'] = $familyId;
            $id = $this->db->insert('sk_guardians', $data);
            $this->audit->log('guardian', $id, 'create', null, $data);
            return $id;
        });
    }

    public function updateGuardian(int $id, array $data): void
    {
        $before = $this->guardian($id) ?? throw new \RuntimeException('Responsable no encontrado');
        $data = self::pick($data, self::GUARDIAN_FIELDS);
        self::validateEmail($data['email'] ?? null);
        $this->db->transaction(function () use ($id, $before, $data) {
            if (!empty($data['is_payer'])) {
                $this->db->run('UPDATE sk_guardians SET is_payer = 0 WHERE family_id = ? AND id <> ?', [$before['family_id'], $id]);
            }
            $this->db->update('sk_guardians', $data, 'id = ?', [$id]);
            $this->audit->log('guardian', $id, 'update', $before, $data);
        });
    }

    public function addStudent(int $familyId, array $data): int
    {
        $this->family($familyId) ?? throw new \RuntimeException('Familia no encontrada');
        $data = self::pick($data, self::STUDENT_FIELDS);
        self::require($data, ['first_name', 'last_name']);
        $data['family_id'] = $familyId;
        $data['status'] = $data['status'] ?? 'active';
        $id = $this->db->insert('sk_students', $data);
        $this->audit->log('student', $id, 'create', null, $data);
        return $id;
    }

    public function updateStudent(int $id, array $data): void
    {
        $before = $this->db->one('SELECT * FROM sk_students WHERE id = ?', [$id]) ?? throw new \RuntimeException('Alumno no encontrado');
        $data = self::pick($data, self::STUDENT_FIELDS);
        if (isset($data['family_id'])) {
            unset($data['family_id']);
        }
        $this->db->update('sk_students', $data, 'id = ?', [$id]);
        $this->audit->log('student', $id, 'update', $before, $data);
    }

    public function moveStudent(int $studentId, int $newFamilyId): void
    {
        $before = $this->db->one('SELECT * FROM sk_students WHERE id = ?', [$studentId]) ?? throw new \RuntimeException('Alumno no encontrado');
        $this->family($newFamilyId) ?? throw new \RuntimeException('Familia no encontrada');
        $this->db->update('sk_students', ['family_id' => $newFamilyId], 'id = ?', [$studentId]);
        $this->audit->log('student', $studentId, 'move_family', $before, ['family_id' => $newFamilyId]);
    }

    public static function pick(array $data, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                $out[$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
            }
        }
        return $out;
    }

    private static function require(array $data, array $fields): void
    {
        foreach ($fields as $f) {
            if (!isset($data[$f]) || $data[$f] === '') {
                throw new ValidationException("El campo «{$f}» es obligatorio.");
            }
        }
    }

    private static function validateEmail(?string $email): void
    {
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('El correo electrónico no es válido.');
        }
    }
}
