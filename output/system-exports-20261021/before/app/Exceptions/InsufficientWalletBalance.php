<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InsufficientWalletBalance extends HttpException
{
    public function __construct(public readonly int $available, public readonly int $required)
    {
        parent::__construct(402, 'Insufficient NUERS wallet balance. Keep this transaction on the client and retry after funding.');
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'INSUFFICIENT_WALLET_BALANCE', 'currency' => 'PHP', 'available_centavos' => $this->available, 'required_centavos' => $this->required, 'shortage_centavos' => max(0, $this->required - $this->available), 'retry_after_topup' => true, 'transaction_saved' => false, 'retained_by' => 'client'], 402);
    }
}
