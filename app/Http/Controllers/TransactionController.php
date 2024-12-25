<?php

namespace App\Http\Controllers;

use App\Models\Transaction;

class TransactionController extends Controller
{
    public function store()
    {
        return $validated = request()->validate([
            //
        ]);

        Transaction::create($validated);
    }
}
