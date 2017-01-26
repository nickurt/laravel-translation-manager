<?php namespace Barryvdh\TranslationManager;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Events\Dispatcher;
use Barryvdh\TranslationManager\Models\Translation;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

class Manager{

    /** @var \Illuminate\Foundation\Application  */
    protected $app;
    /** @var \Illuminate\Filesystem\Filesystem  */
    protected $files;
    /** @var \Illuminate\Events\Dispatcher  */
    protected $events;

    protected $config;

    public function __construct(Application $app, Filesystem $files, Dispatcher $events)
    {
        $this->app = $app;
        $this->files = $files;
        $this->events = $events;
        $this->config = $app['config']['translation-manager'];
    }

    public function missingKey($namespace, $group, $key)
    {
        if(!in_array($group, $this->config['exclude_groups'])) {
            Translation::firstOrCreate(array(
                'locale' => $this->app['config']['app.locale'],
                'group' => $group,
                'key' => $key,
            ));
        }
    }

    public function importTranslations($replace = false)
    {
        $modules = \Module::all();

        foreach($modules as $modulesPathList) {
            $dirs[] = base_path('app/Modules/'.$modulesPathList['name'].'/Resources/Lang/');
        }

        $counter = 0;
        foreach($this->files->directories($dirs) as $langPath){
            $locale = basename($langPath);

            foreach($this->files->allfiles($langPath) as $file) {

                $info = pathinfo($file);
                $group = $info['filename'];

                $namespace = strtolower(array_filter(explode('/', $info['dirname']))[count(array_filter(explode('/', $info['dirname'])))-3]);

                if(in_array($group, $this->config['exclude_groups'])) {
                    continue;
                }

                $subLangPath = str_replace($langPath . DIRECTORY_SEPARATOR, "", $info['dirname']);
                if ($subLangPath != $langPath) {
                    $group = $subLangPath . "/" . $group;
                }

                $translations = \Lang::getLoader()->load($locale, $group, $namespace);
                if ($translations && is_array($translations)) {
                    foreach(array_dot($translations) as $key => $value){
                        // process only string values
                        if(is_array($value)){
                            continue;
                        }
                        $value = (string) $value;
                        $translation = Translation::firstOrNew(array(
                            'locale' => $locale,
                            'group' => $namespace.'::'.$group,
                            'key' => $key,
                        ));

                        // Check if the database is different then the files
                        $newStatus = $translation->value === $value ? Translation::STATUS_SAVED : Translation::STATUS_CHANGED;
                        if($newStatus !== (int) $translation->status){
                            $translation->status = $newStatus;
                        }

                        // Only replace when empty, or explicitly told so
                        if($replace || !$translation->value){
                            $translation->value = $value;
                        }

                        $translation->save();

                        $counter++;
                    }
                }
            }
        }
        return $counter;
    }

    public function findTranslations($path = null)
    {
        $path = $path ?: base_path('app/Modules');
        $keys = array();
        $functions =  array('trans', 'trans_choice', 'Lang::get', 'Lang::choice', 'Lang::trans', 'Lang::transChoice', '@lang', '@choice');
        $pattern =                              // See http://regexr.com/392hu
            "[^\w|>]".                          // Must not have an alphanum or _ or > before real method
            "(".implode('|', $functions) .")".  // Must start with one of the functions
            "\(".                               // Match opening parenthese
            "[\'\"]".                           // Match " or '
            "(".                                // Start a new group to match:
            "[a-zA-Z0-9_:-]+".              // Must start with group
            "([.][^\1)]+)+".                // Be followed by one or more items/keys
            ")".                                // Close group
            "[\'\"]".                           // Closing quote
            "[\),]";                            // Close parentheses or new parameter

        // Find all PHP + Twig files in the app folder, except for storage
        $finder = new Finder();
        $finder->in($path)->exclude('storage')->name('*.php')->name('*.twig')->files();

        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            // Search the current file for the pattern
            if(preg_match_all("/$pattern/siU", $file->getContents(), $matches)) {
                // Get all matches
                foreach ($matches[2] as $key) {
                    $keys[] = $key;
                }
            }
        }
        // Remove duplicates
        $keys = array_unique($keys);

        // Add the translations to the database, if not existing.
        foreach($keys as $key){
            // Split the group and item
            list($group, $item) = explode('.', $key, 2);
            $this->missingKey('', $group, $item);
        }

        // Return the number of found translations
        return count($keys);
    }

    public function exportTranslations($group)
    {
        if(!in_array($group, $this->config['exclude_groups'])) {
            if($group == '*')
                return $this->exportAllTranslations();

            $tree = $this->makeTree(Translation::where('group', $group)->whereNotNull('value')->get());

            $modules = \Module::all();
            $modules = $modules->groupBy(function($item, $key) {
                return strtolower($key);
            })->toArray();

            foreach($tree as $locale => $groups){
                if(isset($groups[$group])){
                    $translations = $groups[$group];
                    $path = base_path('app/Modules/' . $modules[explode('::', $group)[0]][0]['name'] . '/Resources/Lang/').'/'.$locale.'/'.explode('::', $group)[1].'.php';
                    $output = "<?php\n\nreturn ".var_export($translations, true).";\n";
                    $this->files->put($path, $output);
                }
            }
            Translation::where('group', $group)->whereNotNull('value')->update(array('status' => Translation::STATUS_SAVED));
        }
    }

    public function exportAllTranslations()
    {
        $select = '';

        switch (DB::getDriverName()) {
            case 'mysql':
                $select = 'DISTINCT `group`';
                break;

            default:
                $select = 'DISTINCT "group"';
                break;
        }

        $groups = Translation::whereNotNull('value')->select(DB::raw($select))->get('group');

        foreach($groups as $group){
            $this->exportTranslations($group->group);
        }
    }

    public function cleanTranslations()
    {
        Translation::whereNull('value')->delete();
    }

    public function truncateTranslations()
    {
        Translation::truncate();
    }

    protected function makeTree($translations)
    {
        $array = array();
        foreach($translations as $translation){
            array_set($array[$translation->locale][$translation->group], $translation->key, $translation->value);
        }
        return $array;
    }

    public function getConfig($key = null)
    {
        if($key == null) {
            return $this->config;
        }
        else {
            return $this->config[$key];
        }
    }

}