<?php

namespace App\Http\Controllers\Api;

use App\Application\Statements\StatementRecalculator;
use App\Application\Statements\StatementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StatementUpdateRequest;
use App\Http\Resources\StatementResource;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Support\Day;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StatementController extends Controller
{
    /** Makes sure the statement of today's cycle exists (safe to repeat), then lists newest first. */
    public function index(CreditCard $card, StatementService $service): AnonymousResourceCollection
    {
        DB::transaction(fn () => $service->statementFor($card, Day::today()));

        return StatementResource::collection(
            CreditCardStatement::query()->where('credit_card_id', $card->id)->orderByDesc('statement_date')->get()
        );
    }

    public function show(CreditCardStatement $statement): StatementResource
    {
        return new StatementResource($statement->load('payments'));
    }

    public function update(StatementUpdateRequest $request, CreditCardStatement $statement, StatementRecalculator $recalculator): StatementResource
    {
        DB::transaction(function () use ($request, $statement, $recalculator): void {
            $statement->fill($request->validated())->save();
            $recalculator->recalculateCard(CreditCard::query()->findOrFail($statement->credit_card_id));
        });

        return new StatementResource($statement->refresh()->load('payments'));
    }
}