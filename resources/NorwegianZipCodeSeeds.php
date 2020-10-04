<?php

use Illuminate\Database\Seeder;
use NorwegianZipCodes\Models\County;

class NorwegianZipCodeSeeds extends Seeder {
    public function run() {;
        County::create( [ 'id' => '03', 'name' => 'Oslo' ] );
        County::create( [ 'id' => '11', 'name' => 'Rogaland' ] );
        County::create( [ 'id' => '15', 'name' => 'Møre og Romsdal' ] );
        County::create( [ 'id' => '18', 'name' => 'Nordland' ] );
        County::create( [ 'id' => '21', 'name' => 'Svalbard' ] );
        County::create( [ 'id' => '22', 'name' => 'Jan Mayen' ] );
        County::create( [ 'id' => '23', 'name' => 'Kontinentalsokkelen' ] );
        County::create( [ 'id' => '30', 'name' => 'Viken' ] );
        County::create( [ 'id' => '34', 'name' => 'Innlandet' ] );
        County::create( [ 'id' => '38', 'name' => 'Vestfold og Telemark' ] );
        County::create( [ 'id' => '42', 'name' => 'Agder' ] );
        County::create( [ 'id' => '46', 'name' => 'Vestland' ] );
        County::create( [ 'id' => '50', 'name' => 'Trøndelag' ] );
        County::create( [ 'id' => '54', 'name' => 'Troms og Finnmark' ] );
    }
}
