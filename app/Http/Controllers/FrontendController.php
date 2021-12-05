<?php

namespace App\Http\Controllers;

use Exception;
use Midtrans\Snap;
use App\Models\Cart;
use Midtrans\Config;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\CheckoutRequest;

class FrontendController extends Controller
{
    public function index(Request $request)
    {
        //jika ingin di limit
        $products = Product::with(['galleries'])->latest()->limit(8)->get();

        //jika ingin all
        // $products = Product::with(['galleries'])->latest()->get();

        return view('pages.frontend.index', compact('products'));
    }

    public function details(Request $request, $slug)
    {
        //ambil data dari model product->galleries->slug->tampilkan jika tdk ada data munculkan error 404
        $product = Product::with(['galleries'])->where('slug', $slug)->firstOrFail();
        //ambil data rekomendasi dgn random
        $recommendations = Product::with(['galleries'])->inRandomOrder()->limit(4)->get();

        //masukan data ke view
        return view('pages.frontend.details', compact('product', 'recommendations'));
    }

    public function cartAdd(Request $request, $id)
    {
        //panggil mode Cart
        Cart::create([
            'users_id' => Auth::user()->id,
            'products_id' => $id
        ]);

        return redirect('cart');
    }

    public function cartDelete(Request $request, $id)
    {
        //panggil model Cart tampilkan jika
        $item = Cart::findOrFail($id);

        $item->delete();

        redirect('cart');
    }

    public function cart(Request $request)
    {
        //panggil model cart ambil relasi product.galleries, karna nested relationship bisa akses dgn mnambahkan . dan hanya munculkan id yg sedang login dgn Auth dan get datanya
        $carts = Cart::with(['product.galleries'])->where('users_id', Auth::user()->id)->get();

        //tampilkan data ke view
        return view('pages.frontend.cart', compact('carts'));
    }

    public function checkout(CheckoutRequest $request)
    {
        $data = $request->all();

        //get Cart data
        $carts = Cart::with(['product'])->where('users_id', Auth::user()->id)->get();   //panggil model cart.product, ambil berdasarkan users_id, lalu ambil data nya

        //Add to transaction data//
        $data['users_id'] = Auth::user()->id;   //ambil data user yg login
        $data['total_price'] = $carts->sum('product.price');   //itung total harga dgn fungsi sum

        //Create transation
        $transaction = Transaction::create($data);   //panggil model transaction lalu create $data

        //create transaction item
        foreach ($carts as $cart) {
            $items[] = TransactionItem::create([
                'transactions_id' => $transaction->id,   //transactions_id[database] => $transaction yg sudah dibuat diatas lalu ambil id nya
                'users_id' => $cart->users_id,
                'products_id' => $cart->products_id
            ]);
        }

        //Delete cart after transaction 
        Cart::where('users_id', Auth::user()->id)->delete();   //panggil model Cart hapus cart pada user yg sedang login

        //konfigurasi midtrans
        Config::$serverKey = config('services.midtrans.serverkey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$is3ds = config('services.midtrans.is3ds');

        //setup midtrans variable
        $midtrans = [
            'transaction_details' => [
                'order_id' => 'LUX-' . $transaction->id,
                'gross_amount' => (int) $transaction->total_price   //mengkonvert array ke int
            ],
            'customer_details' => [
                'first_name' => $transaction->name,
                'email' => $transaction->email
            ],
            'enabled_payments' => ['gopay', 'bank_transfer'],
            'vtweb' => []
        ];

        //Payment process
        try {
            // Ambil halaman payment midtrans
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;

            //simpan kedatabase
            $transaction->payment_url = $paymentUrl;
            $transaction->save();

            // Redirect ke halaman midtrans
            return redirect($paymentUrl);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function success(Request $request)
    {
        return view('pages.frontend.success');
    }
}
