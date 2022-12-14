<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeliverRequest;
use App\Http\Requests\ProductStoreRequest;
use App\Models\Attribute;
use App\Models\OrderServiceDelivery;
use App\Models\OrderServiceRequirement;
use App\Models\Product;
use App\Models\ProductsCategorie;
use App\Models\ProductsTaxOption;
use App\Models\ProductsVariant;
use App\Models\ProductTag;
use App\Models\ProductTagsRelationship;
use App\Models\SellerPaymentMethod;
use App\Models\SellersProfile;
use App\Models\SellersWalletHistory;
use App\Models\SellerWalletWithdrawal;
use App\Models\ServiceOrder;
use App\Models\ServicePost;
use App\Models\ServiceTags;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserChat;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SellerController extends Controller
{
    //
    public function dashboard()
    {
        $products = Product::where('vendor', auth()->id())->get();
        $seller = SellersProfile::where('user_id', auth()->id())->firstOrFail();
        $withdrawable = SellersWalletHistory::where('user_id', auth()->id())
            ->where('type', 'add')
            ->where('status', 1)
            ->whereDate('updated_at', '<', date('Y-m-d', strtotime(Carbon::today()->toDateString() . " -14 days")))
            ->select('amount')
            ->get()
            ->sum('amount') - SellersWalletHistory::where('user_id', auth()->id())
            ->where('type', 'withdraw')
            ->select('amount')
            ->get()
            ->sum('amount');
        $totalEarned = SellersWalletHistory::where('user_id', auth()->id())->where('type', 'add')->select('amount')->get()->sum('amount');

        $userchat = UserChat::where('user_id', Auth::id())->first();
        if (!$userchat) {
            $userchat = new UserChat();
            $userchat->token = md5(uniqid());
            $userchat->user_id = Auth::id();
            $userchat->name = Auth::user()->first_name . " " . Auth::user()->last_name;
            $userchat->user_image = Auth::user()->uploads->file_name;
            $userchat->save();
        }

        return view('seller.dashboard')->with([
            'products' => $products,
            'seller' => $seller,
            'withdrawable' => $withdrawable,
            'totalEarned' => $totalEarned,
        ]);
    }
    /**
     * Show seller'sproduct create view
     */
    public function createProduct()
    {
        return view('seller.products.create', [
            'attributes' => Attribute::orderBy('id', 'DESC')->get(),
            'categories' => ProductsCategorie::all(),
            'tags' => ProductTag::all(),
            'taxes' => ProductsTaxOption::all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function storeProduct(ProductStoreRequest $req)
    {
        $tags = (array) $req->input('tags');
        $variants = (array) $req->input('variant');
        $attributes = implode(",", (array) $req->input('attributes'));
        $values = implode(",", (array) $req->input('values'));
        $data = $req->all();
        $data['vendor'] = auth()->id();
        $data['price'] = Product::stringPriceToCents($req->price);
        $data['is_digital'] = 1;
        $data['status'] = 2;
        $data['is_virtual'] = 0;
        $data['is_backorder'] = 0;
        $data['is_madetoorder'] = 0;
        $data['is_trackingquantity'] = 0;
        $data['product_attributes'] = $attributes;
        $data['product_attribute_values'] = $values;
        $data['slug'] = str_replace(" ", "-", strtolower($req->name));
        $slug_count = Product::where('slug', $data['slug'])->count();
        if ($slug_count) {
            $data['slug'] = $data['slug'] . '-' . ($slug_count + 1);
        }
        $product = Product::create($data);
        $id_product = $product->id;

        foreach ($variants as $variant) {
            $variant_data = $variant;
            $variant_data['product_id'] = $id_product;
            $variant_data['variant_price'] = Product::stringPriceToCents($variant_data['variant_price']);

            ProductsVariant::create($variant_data);
        }

        foreach ($tags as $tag) {
            $id_tag = (!is_numeric($tag)) ? $this->registerNewTag($tag) : $tag;
            ProductTagsRelationship::create([
                'id_tag' => $id_tag,
                'id_product' => $id_product,
            ]);
        }

        return redirect()->route('seller.dashboard');
    }

    /**
     * Transaction History
     */
    public function transactionHistory()
    {
        $transactions = SellersWalletHistory::where('user_id', auth()->id())->orderBy('created_at', 'DESC')->get();
        return view('seller.history', ['transactions' => $transactions]);
    }

    private function registerNewTag($tag)
    {
        $last = ServiceTags::where('name', $tag)->first();

        if ($last) {
            return $last->id;
        }

        $servicetag = ServiceTags::create([
            'name' => $tag,
            'slug' => $this->slugify($tag),
        ]);
        return $servicetag->id;
    }

    public function slugify($text, string $divider = '-')
    {
        // replace non letter or digits by divider
        $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, $divider);

        // remove duplicate divider
        $text = preg_replace('~-+~', $divider, $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }

    public function service_orders(Request $request)
    {
        $tab = $request->input("tab");
        if (!$tab) {
            $tab = "active";
        }

        $query = ServiceOrder::whereHas('service',
            fn($query) => $query->where('user_id', Auth::id())
        )->with(['user', 'service']);

        $current = Carbon::now();
        switch ($tab) {
            case "active":
                $query->where('status', '<', 3);
                break;
            case "late":
                $query->whereDate('original_delivery_time', '<', $current)->where('status', '<', 3);
                break;
            case "delivered":
                $query->where('status', 4);
                break;
            case "completed":
                $query->where('status', 5);
                break;
            case "canceled":
                $query->where('status', 3);
                break;
            default:
                break;
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        return view('seller.services.orders.index', ['orders' => $orders, 'tab' => $tab]);
    }

    public function service_order_detail($id)
    {
        $order = ServiceOrder::where('order_id', $id)->whereHas('service',
            fn($query) => $query->where('user_id', Auth::id())
        )->with(['user', 'review'])->firstOrFail();

        $answers = OrderServiceRequirement::with('requirement')->where('order_id', $order->id)->get();

        $answers->each(function ($answer) {
            if ($answer->requirement->type == 1) {
                $attach_ids = explode(',', $answer->answer);
                $attaches = [];

                for ($i = 0; $i < count($attach_ids); $i++) {
                    $upload = Upload::findOrFail($attach_ids[$i]);
                    array_push($attaches, $upload);
                }

                $answer->attaches = $attaches;
            } else if ($answer->requirement->type == 3) {
                $answer->answers = explode(',', $answer->answer);
            }
        });

        $deliveries = OrderServiceDelivery::with('revision')->where('order_id', $order->id)->get();

        $deliveries->each(function ($delivery) {
            $attach_ids = explode(',', $delivery->attachment);
            $attaches = [];

            for ($i = 0; $i < count($attach_ids); $i++) {
                $upload = Upload::findOrFail($attach_ids[$i]);
                array_push($attaches, $upload);
            }

            $delivery->attaches = $attaches;
        });

        $buyer = User::with('uploads')->findOrFail($order->user_id);

        return view('seller.services.orders.detail', [
            'order' => $order,
            'answers' => $answers,
            'deliveries' => $deliveries,
            'buyer' => $buyer,
        ]);
    }

    public function service_order_deliver(DeliverRequest $request)
    {
        $order_id = $request->order_id;
        $message = $request->message;
        $attach = $request->attach;

        $order = ServiceOrder::findOrFail($order_id);
        $order->status = 4;
        $order->save();

        $delivery = new OrderServiceDelivery();
        $delivery->order_id = $order_id;
        $delivery->message = $message;
        $delivery->attachment = $attach;
        $delivery->save();

        return redirect()->back()->with("success", "Your service successfuly delivered!");
    }

    public function withdraw()
    {
        $seller = SellersProfile::where('user_id', auth()->id())->firstOrFail();
        $withdrawable = SellersWalletHistory::where('user_id', auth()->id())
            ->where('type', 'add')
            ->where('status', 1)
            ->whereDate('updated_at', '<', date('Y-m-d', strtotime(Carbon::today()->toDateString() . " -14 days")))
            ->select('amount')
            ->get()
            ->sum('amount') - SellersWalletHistory::where('user_id', auth()->id())
            ->where('type', 'withdraw')
            ->select('amount')
            ->get()
            ->sum('amount');
        $totalEarned = SellersWalletHistory::where('user_id', auth()->id())->where('type', 'add')->select('amount')->get()->sum('amount');
        $payment_methods = SellerPaymentMethod::all();

        return view('seller.withdraw', compact('seller', 'withdrawable', 'totalEarned', 'payment_methods'));
    }

    public function withdraw_post(Request $request)
    {
        $question_ids = $request->question;
        $answers = $request->answer;
        $amount = $request->amount * 100;

        $seller_profile = SellersProfile::where('user_id', Auth::id())->firstOrFail();

        if ($seller_profile->wallet < $amount || $amount <= 0) {
            return redirect()->back()->with('error', 'Insuficient funds');
        }

        $seller_profile->wallet = $seller_profile->wallet - $amount;
        $seller_profile->save();

        $withdraw_history = new SellerWalletWithdrawal();
        $withdraw_history->user_id = Auth::id();
        $withdraw_history->amount = $amount;
        $withdraw_history->payment_method_name = SellerPaymentMethod::findOrFail($request->method)->name;

        for ($i = 0; $i < count($question_ids); $i++) {
            $withdraw_history['q' . ($question_ids[$i] + 1)] = $answers[$i];
        }

        $withdraw_history->save();

        $wallet_history = new SellersWalletHistory();
        $wallet_history->user_id = Auth::id();
        $wallet_history->amount = $amount;
        $wallet_history->type = "withdraw";
        $wallet_history->save();

        return redirect()->back()->with('success', 'Withdraw is in progress');
    }

    public function withdraw_history()
    {
        $histories = SellerWalletWithdrawal::with('method')->where('user_id', Auth::id())->get();

        return view('seller.withdraw_history', compact('histories'));
    }

    public function seller_profile($username)
    {
        $seller = SellersProfile::withWhereHas('user', fn($query) => $query->where('username', $username))->with('user.uploads')->firstOrFail();
        $products = Product::with(['uploads', 'product_category'])->where('vendor', $seller->user_id)->paginate(6, '*', 'product');
        $services = ServicePost::with(['uploads', 'categories.category'])->where('user_id', $seller->user_id)->paginate(6, '*', 'service');

        $userchat = UserChat::where('user_id', $seller->user_id)->first();
        if (!$userchat) {
            $userchat = new UserChat();
            $userchat->token = md5(uniqid());
            $userchat->user_id = $seller->user_id;
            $userchat->name = $seller->user->first_name . " " . $seller->user->last_name;
            $userchat->user_image = $seller->user->uploads->file_name;
            $userchat->save();
        }

        if (Auth::check()) {
            if (!UserChat::where('user_id', Auth::id())->count()) {
                $mychat = new UserChat();
                $mychat->token = md5(uniqid());
                $mychat->user_id = Auth::id();
                $mychat->name = Auth::user()->first_name . " " . Auth::user()->last_name;
                $mychat->user_image = Auth::user()->uploads->file_name;
                $mychat->save();
            }
        }

        return view('seller_profile', compact('seller', 'products', 'services', 'userchat'));
    }

    public function profile()
    {
        $seller = SellersProfile::withWhereHas('user', fn($query) => $query->where('id', Auth::id()))->with('user.uploads')->firstOrFail();
        $payment_methods = SellerPaymentMethod::all();

        return view('seller.profile', compact('seller', 'payment_methods'));
    }

    public function save_profile(Request $request)
    {
        $seller = SellersProfile::withWhereHas('user', fn($query) => $query->where('id', Auth::id()))->firstOrFail();

        $seller->slogan = $request->slogan;
        $seller->about = $request->about;
        $seller->default_payment_method = $request->method;
        $seller->save();

        if ($request->avatar) {
            $user = $seller->user;
            $user->avatar = $request->avatar;
            $user->save();
        }

        return redirect()->back()->with("success", "Saved data");
    }
}