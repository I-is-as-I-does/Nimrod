<?php
/* This file is part of Nimrod | SSITU | (c) 2021 I-is-as-I-does */
namespace SSITU\Nimrod;

class Nimrod implements \SSITU\Blueprints\FlexLogsInterface

{

    use Blueprints\FlexLogsTrait;

    private $dfltLang;
    private $sessionKey;
    private $getKey;
    private $strictMode;

    private $translRsrc;
    private $queryLang;

    public function __construct(array $translRsrcMap, string $dfltLang, string $sessionKey = 'SSITU_lang', string $getKey = 'lang', bool $strictMode = false)
    {
        $this->dfltLang = $dfltLang;
        $this->sessionKey = $sessionKey;
        $this->getKey = $getKey;
        $this->strictMode = $strictMode;

        $this->bulk_load_translRsrc($translRsrcMap);

        $this->set_session();
        $this->set_Lang();
    }

    public function t_(string $txtKey, ?array $subst = [], ?bool $strict = null, ?string $lang = null)
    {
        if (is_null($lang)) {
            $lang = $this->get_currentLang();
        }
        if (is_null($strict)) {
            $strict = $this->strictMode;
        }
        $transl = $this->transl($txtKey, $lang, $strict);
        if (!empty($transl) && !empty($subst)) {
            $transl = $this->subst($transl, $subst, $strict);
        }
        return $transl;
    }

    public function set_dfltLang(string $dfltLang)
    {
        $this->dfltLang = $dfltLang;
    }

    public function set_sessionKey(string $sessionKey)
    {
        $this->sessionKey = $sessionKey;
    }

    public function set_getKey(string $getKey)
    {
        $this->getKey = $getKey;
    }

    public function set_strictMode(bool $strictMode)
    {
        $this->strictMode = $strictMode;
    }

    public function bulk_load_translRsrc(array $translRsrcMap)
    {
        $load = [];
        if (!empty($translRsrcMap)) {
            foreach ($translRsrcMap as $item) {
                if (empty($item['lang']) || empty($item['rsrc'])) {
                    $load[] = false;
                    continue;
                }
                $load[] = $this->load_translRsrc($item['lang'], $item['rsrc']);
            }
        }
        if (in_array(false, $load)) {
            $this->log('error', 'invalid-rsrc-map', $translRsrcMap);
            return false;
        }
        return true;
    }

    public function load_translRsrc(string $lang, array|string $rsrc)
    {
        if (is_array($rsrc) || $rsrc = $this->get_fileRsrc($rsrc)) {
            if (empty($this->translRsrc[$lang])) {
                $this->translRsrc[$lang] = $rsrc;
            } else {
                $this->translRsrc[$lang] = $rsrc + $this->translRsrc[$lang];
            }
            return true;
        }
        $this->log('alert', 'invalid-transl-rsrc', $rsrc);
        return false;
    }

    public function get_dfltLang()
    {
        return $this->dfltLang;
    }

    public function get_avlblLangs()
    {
        return array_keys($this->translRsrc);
    }

    public function get_currentLang()
    {
        return $_SESSION[$this->sessionKey];

    }

    public function get_queryLang()
    {
        if (!isset($this->queryLang)) {
            $this->set_queryLang();
        }
        return $this->queryLang;
    }

    public function set_Lang(?string $langPreference = null, ?bool $strict = null)
    {
        $sessLang = $this->get_currentLang();
        if (!empty($sessLang) && $sessLang == $langPreference && $this->langIsAvailable($sessLang)) {
            return;
        }
        if (empty($langPreference) || !$this->langIsAvailable($langPreference)) {
            if (is_null($strict)) {
                $strict = $this->strictMode;
            }
            $langPreference = $this->get_fallbackLang($langPreference, $strict);
        }

        $this->set_sessionLang($langPreference);
    }

    public function langIsAvailable(?string $lang)
    {
        return !empty($lang) && array_key_exists($lang, $this->translRsrc);
    }

    public function translIsAvailable(string $txtKey, ?string $lang)
    {
        return $this->langIsAvailable($lang) && array_key_exists($txtKey, $this->translRsrc[$lang]);
    }

    protected function set_session()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function set_sessionLang(?string $lang)
    {
        $_SESSION[$this->sessionKey] = $lang;
    }

    protected function get_fileRsrc(string $path)
    {

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext == 'php') {
            return $this->get_PhpRsrc($path);
        } elseif ($ext == 'json') {
            return $this->get_JsonRsrc($path);
        }
        $this->log('error', 'unsupported-rsrc-file-type', $path);
        return false;
    }

    protected function get_PhpRsrc(string $path)
    {
        if (file_exists($path)) {
            require $path;
        }
        return false;
    }

    protected function get_JsonRsrc(string $path)
    {
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true);
        }
        return false;
    }

    protected function set_queryLang()
    {
        $this->queryLang = false;
        if (!empty($this->getKey) && array_key_exists($this->getKey, $_GET)) {
            $queryLang = filter_input('INPUT_GET', $this->getKey, FILTER_SANITIZE_STRING);
            if ($this->langIsAvailable($queryLang)) {
                $this->queryLang = $queryLang;
            }
        }
    }

    protected function get_fallbackLang(?string $givenLang, bool $strict)
    {
        if ($getLang = $this->get_queryLang()) {
            return $getLang;
        }
        if ($givenLang != $this->dfltLang && $this->langIsAvailable($this->dfltLang)) {
            return $this->dfltLang;
        }
        if (!$strict) {
            if ($givenLang != 'en' && $this->langIsAvailable('en')) {
                return 'en';
            }
            foreach ($this->get_avlblLangs() as $langOpt) {
                if ($langOpt != $givenLang) {
                    return $langOpt;
                }
            }
        }
        $this->log('alert', 'no-lang-available');
        return false;
    }

    private function transl(string $txtKey, ?string $lang, bool $strict)
    {
        if ($this->translIsAvailable($txtKey, $lang)) {
            return $this->translRsrc[$lang][$txtKey];
        }

        $this->log('error', 'missing-translation', ['txtKey' => $txtKey, 'lang' => $lang]);

        if ($fallback = $this->get_fallbackLang($lang, $strict)) {
            return $this->transl($txtKey, $fallback, $strict);
        }

        $this->log('error', 'no-translation-found', ['txtKey' => $txtKey]);
        if ($strict) {
            return false;
        }
        return str_replace(['.', '-', '_'], ' ', $txtKey);
    }

    private function subst(string $transl, array $subst, bool $strict)
    {
        foreach ($subst as $hook => $replc) {
            $hook = '{{' . $hook . '}}';
            if (strpos($transl, $hook) === false) {
                $this->log('error', 'hook-not-found', ['transl' => $transl, 'hook' => $hook]);
                if ($strict) {
                    return false;
                }
                continue;
            }
            $transl = str_replace($hook, $replc, $transl);
        }
        return $transl;
    }

}
