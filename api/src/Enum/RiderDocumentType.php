<?php

declare(strict_types=1);

namespace App\Enum;

enum RiderDocumentType: string
{
    case DRIVERS_LICENSE = 'drivers_license';
    case VEHICLE_REGISTRATION = 'vehicle_registration';
    case VALID_ID = 'valid_id';
    case POLICE_CLEARANCE = 'police_clearance';
}
