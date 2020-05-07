<?php

namespace App\Shop;

use App\Models\Shop\ProductVariation;
use App\Models\Shop\ShippingMethod;
use App\Models\User;

/**
 * Class Cart
 * @package App\Shop
 */
class Cart
{
    /**
     * @var User
     */
    protected $user;

    /**
     * @var bool
     */
    protected $changed = false;

    /**
     * @var int
     */
    protected $shipping;

    /**
     * Cart constructor.
     * @param User $user
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * @return mixed
     */
    public function products()
    {
        return $this->user->cart;
    }

    /**
     * @param $shippingId
     * @return $this
     */
    public function withShipping($shippingId)
    {
        $this->shipping = ShippingMethod::find($shippingId);

        return $this;
    }

    /**
     * @param array $products
     */
    public function add(array $products)
    {
        $this->user->cart()->syncWithoutDetaching($this->getPayload($products));
    }

    /**
     * @param array $products
     * @return array
     */
    public function getPayload(array $products)
    {
        return collect($products)->keyBy('id')->map(function ($product) {
            return [
                'quantity' => $product['quantity'] + $this->getCurrentQuantity($product['id'])
            ];
        })->toArray();
    }

    /**
     * @param int $productId
     * @param int $quantity
     */
    public function update(int $productId, int $quantity)
    {
        $this->user->cart()->updateExistingPivot($productId, [
            'quantity' => $quantity
        ]);
    }

    /**
     * @param int $productId
     */
    public function delete(int $productId)
    {
        $this->user->cart()->detach($productId);
    }

    public function empty()
    {
        $this->user->cart()->detach();
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return $this->user->cart->sum('pivot.quantity') <= 0;
    }

    /**
     * @return Money
     */
    public function subtotal()
    {
        $subtotal = $this->user->cart->sum(function ($product) {
            return $product->price->amount() * $product->pivot->quantity;
        });

        return new Money($subtotal);
    }

    /**
     * @return Money
     */
    public function total()
    {
        if ($this->shipping) {
            return $this->subtotal()->add($this->shipping->price);
        }

        return $this->subtotal();
    }

    /**
     * @param $productId
     * @return int
     */
    protected function getCurrentQuantity($productId)
    {
        if ($product = $this->user->cart->where('id', $productId)->first()) {
            return $product->pivot->quantity;
        }

        return 0;
    }

    public function sync()
    {
        $this->user->cart->each(function (ProductVariation $product) {
            $stockQuantity = $product->minStock($product->pivot->quantity);

            if ($stockQuantity != $product->pivot->quantity)
                $this->changed = true;

            if ($stockQuantity > 0)
                $product->pivot->update([
                    'quantity' => $stockQuantity
                ]);
            else
                $product->pivot->delete();
        });
    }

    /**
     * @return bool
     */
    public function hasChanged()
    {
        return $this->changed;
    }
}
