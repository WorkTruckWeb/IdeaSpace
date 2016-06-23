<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Theme;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Event;
use App\GenericFile;
use Auth;
use File;
use Image;
use Validator;
use App\Space;
use App\FieldControl;
use App\FieldDataImage;
use App\FieldDataText;
use App\Setting;
use Route;
use Log;

class SpaceAddController extends Controller {


    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct() {

        $this->middleware('auth');
        $this->middleware('register.theme.eventlistener');
    }

    /**
     * Show the add space and select theme page.
     *
     * @return Response
     */
    public function select_theme() {

        $themes = Theme::where('status', Theme::STATUS_ACTIVE)->get();

        $themes_mod = array();

        foreach ($themes as $theme) {
            $config = json_decode($theme->config, true);
            $theme_mod = array();
            $theme_mod['id'] = $theme->id;
            $theme_mod['theme-name'] = $config['#theme-name'];
            $theme_mod['theme-description'] = $config['#theme-description'];
            $theme_mod['theme-compatibility'] = explode(',', $config['#theme-compatibility']);
            $theme_mod['screenshot'] = url($theme->root_dir . '/' . Theme::SCREENSHOT_FILE);
            $themes_mod[] = $theme_mod; 
        }

        $vars = [
            'themes' => $themes_mod,
            'js' => array(asset('public/assets/admin/space/js/space_add_select_theme.js')),
            'css' => array(asset('public/assets/admin/space/css/space_add_select_theme.css'))
        ];
        
        return view('admin.space.space_add_select_theme', $vars);
    }


    /**
     * Ajax post submitting theme id.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function select_theme_submit(Request $request) {

        $all = $request->all();

        if (array_has($all, 'id')) {

          $request->session()->put('theme-id', $all['id']);

          return response()->json(['redirect' => url('admin/space/add')]);

        } else {
            abort(404);
        }
    }


    /**
     * The space add page.
     *
     * @return Response
     */
    public function space_add() {

        $theme_id = session('theme-id');        

        try {
            $theme = Theme::where('id', $theme_id)->where('status', Theme::STATUS_ACTIVE)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return redirect()->route('space_add_select_theme');
        }

        /* if session has vars then take these; for validation errors and keeping form field values */ 
        /*if (session()->has('vars')) {
            $vars = session()->get('vars');
            session()->forget('vars');
        } else {
            $vars = $this->prepare_config($theme);
        }*/

        $vars = $this->process_theme($theme);

        $vars['space_status'] = Space::STATUS_DRAFT;

        return response()->view('admin.space.space_add', $vars);
    }


    /**
     * Save space and redirect to content page.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function space_add_content_add_submit(Request $request) {

        return $this->space_add_submit_process($request);
    }


    /**
     * Add space submission.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function space_add_submit(Request $request) {

        return $this->space_add_submit_process($request);
    }


    /**
     * Space submit process.
     *
     * @param Request $request
     *
     * @return Response
     */
    private function space_add_submit_process(Request $request) {

        $user = Auth::user();
        $theme = Theme::where('id', $request->input('theme_id'))->first();

        $validation_rules = [
            'space_title' => 'required|max:512',
            'space_uri' => 'required|max:255',
        ];

        $validation_messages = [
            'space_title.required' => trans('space_add_controller.validation_space_title_required'),
            'space_title.max' => trans('space_add_controller.validation_space_title_max', ['max' => ':max']),
            'space_uri.required' => trans('space_add_controller.validation_space_uri_required'),
            'space_uri.max' => trans('space_add_controller.validation_space_uri_max', ['max' => ':max']),
        ];

        $validator = Validator::make($request->all(), $validation_rules, $validation_messages);

        if ($validator->fails()) {
            $vars = $this->process_theme($theme);
            return redirect('admin/space/add')->withErrors($validator)->withInput()->with('vars', $vars);
        }

        /* generate url-friendly slug */
        $space_uri = str_slug($request->input('space_uri'));

        /* check if uri exists already */
        try {
            $existing_space = Space::where('uri', $space_uri)->firstOrFail();
            if ($existing_space != null) {
                $validator->after(function($validator) {
                    $validator->errors()->add('space_uri', trans('space_add_controller.validation_space_uri_exists'));
                });              
                if ($validator->fails()) {
                    $vars = $this->process_theme($theme);
                    return redirect('admin/space/add')->withErrors($validator)->withInput()->with('vars', $vars);
                }
            }
        } catch (ModelNotFoundException $e) {
        }

        /* check if uri exists as system uri already */
        $routeCollection = Route::getRoutes();
        foreach ($routeCollection as $route) {
            if ($route->getPath() == $space_uri) {
                $validator->after(function($validator) {
                    $validator->errors()->add('space_uri', trans('space_add_controller.validation_space_uri_exists'));
                });
                if ($validator->fails()) {
                    $vars = $this->process_theme($theme);
                    return redirect('admin/space/add')->withErrors($validator)->withInput()->with('vars', $vars);
                }
            }
        }       

        $space = [
            'user_id' => $user->id,
            'theme_id' => $theme->id,
            'uri' => $space_uri, 
            'title' => $request->input('space_title'), 
            'status' => $request->input('space_status')
        ];
  
        /* fire event */
        Event::fire('space.save_pre', $space);

        $space = Space::create($space);

        /* fire event */
        Event::fire('space.save', $space);

        if ($request->input('contenttype_key') != '') {
            /* space_uri should we shown in an url-friendly way */
            return redirect('admin/space/' . $space->id . '/edit/' . $request->input('contenttype_key') . '/add')->withInput($request->except('space_uri'))->with('space_uri', $space_uri)->with('alert-success', trans('space_add_controller.space_saved'));
        }

        /* space_uri should we shown in an url-friendly way */
        return redirect('admin/space/' . $space->id . '/edit')->withInput($request->except('space_uri'))->with('space_uri', $space_uri)->with('alert-success', trans('space_add_controller.space_saved'));

    }


    /**
     * Process theme.
     *
     * @param Theme $theme The theme which is currently in use.
     *
     * @return $vars The vars array for the view.
     */
    private function process_theme($theme) {

        $theme_mod = array();

        /* convert to an array */
        $config = json_decode($theme->config, true);
        $theme_mod = array();
        $theme_mod['id'] = $theme->id;
        $theme_mod['theme-name'] = $config['#theme-name'];
        $theme_mod['theme-version'] = $config['#theme-version'];
        $theme_mod['theme-author-name'] = $config['#theme-author-name'];
        $theme_mod['theme-screenshot'] = url($theme->root_dir . '/' . Theme::SCREENSHOT_FILE);

        foreach ($config['#content-types'] as $key => $value) {

            $theme_mod['contenttypes'][$key] = [
                'label' => $value['#label'], 
                'description' => $value['#description']
                ]; 
        }

        /* store types in session in order to validate type on json media upload submission */
        //session(['types' => $types]);

        $vars = [
            'space_theme_id' => $theme->id,
            'theme' => $theme_mod,
            'js' => [asset('public/assets/admin/space/js/space_add.js')], 
            'css' => [asset('public/assets/admin/space/css/space_add.css')]
        ];
      
        return $vars;
    }


}
