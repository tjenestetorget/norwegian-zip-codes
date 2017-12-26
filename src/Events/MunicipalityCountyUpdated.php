<?php 

namespace NorwegianZipCodes\Events;

use NorwegianZipCodes\Models\Municipality;

/**
 * @package NorwegianZipCodes\Events
 */
class MunicipalityCountyUpdated {

    protected $municipality;
    protected $oldCountyId;

	public function __construct(Municipality $municipality, string $oldCountyId) {
	    $this->municipality = $municipality;
	    $this->oldCountyId = $oldCountyId;
	}
}
