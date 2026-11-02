<?php
// Columnas nuevas en tablas de GYM One. Se hace en PHP porque MySQL (a diferencia de MariaDB)
// no soporta "ADD COLUMN IF NOT EXISTS".
return function (\Sankris\Migrator $m) {
    $m->addColumn('timetable', 'trainer_id', 'int(11) DEFAULT NULL');
    $m->addColumn('timetable', 'room', 'varchar(50) DEFAULT NULL');
    $m->addColumn('timetable', 'program_id', 'int(11) DEFAULT NULL');
    $m->addColumn('timetable', 'capacity', 'int(11) DEFAULT NULL');
    $m->addColumn('timetable', 'is_evaluation', 'tinyint(1) NOT NULL DEFAULT 0');
    $m->addColumn('tickets', 'program_id', 'int(11) DEFAULT NULL');
    $m->addColumn('users', 'program_id', 'int(11) DEFAULT NULL');
    $m->addColumn('users', 'gym_level', 'varchar(50) DEFAULT NULL');
    $m->addColumn('users', 'guardian_name', 'varchar(100) DEFAULT NULL');
    $m->addColumn('users', 'guardian_phone', 'varchar(30) DEFAULT NULL');
    $m->addColumn('users', 'medical_notes', 'text DEFAULT NULL');
    // Ventanas de reserva/cancelación y reposiciones, configurables por programa
    $m->addColumn('programs', 'booking_open_days', 'int(11) NOT NULL DEFAULT 14');
    $m->addColumn('programs', 'cancel_deadline_hours', 'int(11) NOT NULL DEFAULT 12');
    $m->addColumn('programs', 'makeups_per_month', 'int(11) NOT NULL DEFAULT 2');
};
