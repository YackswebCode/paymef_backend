<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Invoice
    {
        $schoolId = $this->resolveSchoolId($user, $data['school_id'] ?? null);

        $student = User::query()
            ->whereKey($data['student_id'])
            ->where('role', 'student')
            ->first();

        if (! $student) {
            throw ValidationException::withMessages([
                'student_id' => 'The selected student is invalid.',
            ]);
        }

        if ((int) $student->school_id !== $schoolId) {
            throw ValidationException::withMessages([
                'student_id' => 'The student does not belong to this school.',
            ]);
        }

        return Invoice::create([
            'school_id' => $schoolId,
            'student_id' => $student->id,
            'reference' => $data['reference'] ?? 'INV-'.Str::upper(Str::random(10)),
            'description' => $data['description'] ?? null,
            'amount_minor' => $data['amount_minor'],
            'currency' => $data['currency'] ?? 'NGN',
            'status' => $data['status'] ?? 'issued',
            'due_date' => $data['due_date'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, Invoice>
     */
    public function bulk(User $user, array $rows): array
    {
        $schoolId = $this->resolveSchoolId($user, $rows[0]['school_id'] ?? null);

        // Validate the whole batch before writing anything so a single bad row
        // cannot leave a half-created term of invoices behind.
        foreach ($rows as $index => $row) {
            if (empty($row['student_id']) || empty($row['amount_minor'])) {
                throw ValidationException::withMessages([
                    "invoices.$index.amount_minor" => 'Each invoice needs a student and an amount.',
                ]);
            }

            $student = User::query()
                ->whereKey($row['student_id'])
                ->where('role', 'student')
                ->first();

            if (! $student) {
                throw ValidationException::withMessages([
                    "invoices.$index.student_id" => 'The selected student is invalid.',
                ]);
            }

            if ((int) $student->school_id !== $schoolId) {
                throw ValidationException::withMessages([
                    "invoices.$index.student_id" => 'The student does not belong to this school.',
                ]);
            }
        }

        return DB::transaction(fn (): array => array_map(
            fn (array $row) => $this->create($user, $row),
            $rows
        ));
    }

    private function resolveSchoolId(User $user, mixed $requestedSchoolId): int
    {
        if ($user->isAdmin()) {
            if (empty($requestedSchoolId)) {
                throw ValidationException::withMessages([
                    'school_id' => 'The school_id field is required for administrators.',
                ]);
            }

            return (int) $requestedSchoolId;
        }

        if ($user->school_id === null) {
            abort(403, 'Your account is not attached to a school.');
        }

        // A school admin can never redirect an invoice to another school.
        if ($requestedSchoolId !== null && (int) $requestedSchoolId !== (int) $user->school_id) {
            abort(403, 'You can only create invoices for your own school.');
        }

        return (int) $user->school_id;
    }
}
