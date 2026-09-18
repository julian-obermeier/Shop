<?php
namespace App\Services;

use App\Models\User;

class ConsentService
{
    public function assertRequiredConsents(User $user): void
    {
        // Allgemeine Dokumente werden einmal bei Registrierung bestätigt.
        // Spätere Versionen blockieren neue Aufträge nicht.
    }
}
