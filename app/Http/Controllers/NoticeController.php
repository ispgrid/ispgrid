<?php

namespace App\Http\Controllers;

use App\Support\NoticePage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * The page customers in the disabled pool see (spec section 5): the router sends their web traffic here. It cannot
 * know who the customer is (requests arrive from the router), so it shows the ISP's notice text from App settings.
 * No session, cookie or asset: every request of every disabled customer lands here.
 */
class NoticeController extends Controller
{
    public function __invoke(): Response
    {
        $settings = NoticePage::settings();

        // In the ISP's language: the customer is unknown here. The web middleware (and its locale) does not run.
        if ($settings['locale'] !== null) {
            App::setLocale($settings['locale']);
        }

        return response()
            ->view('notice', $settings)
            ->header('Cache-Control', 'no-store');
    }
}
