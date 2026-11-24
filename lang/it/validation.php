<?php

/*
| Most common rules translated; anything missing falls back to lang/en.
*/
return [
    'accepted' => 'Il campo :attribute deve essere accettato.',
    'array' => 'Il campo :attribute deve essere un array.',
    'between' => [
        'numeric' => 'Il campo :attribute deve essere compreso tra :min e :max.',
        'string' => 'Il campo :attribute deve contenere tra :min e :max caratteri.',
        'array' => 'Il campo :attribute deve avere tra :min e :max elementi.',
    ],
    'boolean' => 'Il campo :attribute deve essere vero o falso.',
    'confirmed' => 'La conferma di :attribute non corrisponde.',
    'date' => 'Il campo :attribute non è una data valida.',
    'distinct' => 'Il campo :attribute contiene un valore duplicato.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'enum' => 'Il valore selezionato per :attribute non è valido.',
    'exists' => 'Il valore selezionato per :attribute non è valido.',
    'in' => 'Il valore selezionato per :attribute non è valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'lowercase' => 'Il campo :attribute deve essere in minuscolo.',
    'max' => [
        'numeric' => 'Il campo :attribute non può essere maggiore di :max.',
        'string' => 'Il campo :attribute non può superare :max caratteri.',
        'array' => 'Il campo :attribute non può avere più di :max elementi.',
    ],
    'min' => [
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve contenere almeno :min caratteri.',
        'array' => 'Il campo :attribute deve avere almeno :min elementi.',
    ],
    'numeric' => 'Il campo :attribute deve essere un numero.',
    'password' => [
        'letters' => 'Il campo :attribute deve contenere almeno una lettera.',
        'mixed' => 'Il campo :attribute deve contenere almeno una maiuscola e una minuscola.',
        'numbers' => 'Il campo :attribute deve contenere almeno un numero.',
        'symbols' => 'Il campo :attribute deve contenere almeno un simbolo.',
        'uncompromised' => ':attribute è apparsa in una violazione di dati. Scegline un\'altra.',
    ],
    'regex' => 'Il formato del campo :attribute non è valido.',
    'required' => 'Il campo :attribute è obbligatorio.',
    'required_without' => 'Il campo :attribute è obbligatorio quando :values non è presente.',
    'string' => 'Il campo :attribute deve essere una stringa.',
    'unique' => 'Il valore di :attribute è già in uso.',
    'url' => 'Il campo :attribute deve essere un URL valido.',

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'name' => 'nome',
        'club_name' => 'nome del club',
        'city' => 'città',
        'first_name' => 'nome',
        'last_name' => 'cognome',
        'number' => 'numero',
        'opponent' => 'avversario',
        'played_at' => 'data della partita',
        'youtube_url' => 'link YouTube',
        'team_id' => 'squadra',
        'role' => 'ruolo',
    ],
];
