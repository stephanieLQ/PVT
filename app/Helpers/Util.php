<?php

namespace App\Helpers;

use Carbon;
use Config;
use App\RecordType;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use File;

class Util
{
    public static function trim_spaces($string)
    {
        return preg_replace('/[[:blank:]]+/', ' ', $string);
    }

    public static function bool_to_string($value)
    {
        if (is_bool($value)) {
            if ($value) {
                $value = 'SI';
            } else {
                $value = 'NO';
            }
        } else {
            try {
                $value = Carbon::createFromFormat('Y-m-d', $value)->format('d-m-Y');
            } catch (\Exception $e) {}
        }
        return $value;
    }

    public static function translate($string)
    {
        $translation = static::translate_table($string);
        if ($translation) {
            return $translation;
        } else {
            return static::translate_attribute($string);
        }
    }

    public static function translate_table($string)
    {
        if (array_key_exists($string, Config::get('translations'))) {
            return Config::get('translations')[$string];
        } else {
            return null;
        }
    }

    public static function translate_attribute($string)
    {
        $path = app_path() . '/resources/lang/es/validation.php';
        if(@include $path) {
            $translations_file = include(app_path().'/resources/lang/es/validation.php');
        }
        if (isset($translations_file)) {
            if (array_key_exists($string, $translations_file['attributes'])) {
                return $translations_file['attributes'][$string];
            }
        }
        return $string;
    }

    public static function round($value)
    {
        return round($value, 2, PHP_ROUND_HALF_EVEN);
    }

    public static function money_format($value, $literal = false)
    {
        if ($literal) {
            $f = new \NumberFormatter('es', \NumberFormatter::SPELLOUT);
            $data = $f->format(intval($value)) . ' ' . explode('.', number_format(round($value, 2), 2))[1] . '/100';
        } else {
            $data = number_format($value, 2, ',', '.');
        }
        return $data;
    }

    public static function search_sort($model, $request, $filter = [], $relations = [], $pivot = [])
    {
        $query = $model::query();
        if (count($relations) > 0) {
            foreach ($relations as $relation => $constraints) {
                if (count($pivot) > 0) {
                    $query = $query->with([$relation => function ($q) use ($pivot) {
                        $q->select($pivot);
                    }]);
                }
                if (count($constraints) > 0) {
                    $query = $query->whereHas($relation, function($q) use ($constraints) {
                        foreach ($constraints as $column => $constraint) {
                            $q->where($column, $constraint);
                        }
                        return $q;
                    });
                }
            }
        }
        foreach ($filter as $column => $constraint) {
            if (!is_array($constraint)) $constraint = ['=', $constraint];
            $query = $query->where($column, $constraint[0], $constraint[1]);
        }
        if ($request->has('search') || $request->has('sortBy')) {
            $columns = Schema::getColumnListing($model::getTableName());
        }
        if ($request->has('search')) {
            if ($request->search != 'null' && $request->search != '') {
                $search = explode(' ', $request->search);
                $query = $query->where(function ($query) use ($search, $model, $columns) {
                    foreach ($search as $word) {
                        foreach (['d/m/y', 'd-m-y', 'd/m/Y', 'd-m-Y'] as $date_format) {
                            try {
                                $date = Carbon::createFromFormat($date_format, $word)->format('Y-m-d');
                                break;
                            } catch (\Exception $e) {}
                        }
                        if (isset($date)) $word = $date;
                        $query = $query->where(function ($q) use ($word, $model, $columns) {
                            foreach ($columns as $column) {
                                $q->orWhere($column, 'ilike', '%' . $word . '%');
                            }
                        });
                    }
                });
            }
        }
        if ($request->has('sortBy')) {
            if (count($request->sortBy) > 0 && count($request->sortDesc) > 0) {
                foreach ($request->sortBy as $i => $sort) {
                    if (in_array($sort, $columns))
                    $query = $query->orderBy($sort, self::get_bool($request->sortDesc[$i]) ? 'desc' : 'asc');
                }
            }
        }
        return $query->paginate($request->per_page ?? 10);
    }

    public static function pivot_action($relationName, $pivotIds, $message)
    {
        $action = $message . ' ';
        $action .= self::translate($relationName) . ': ';
        if (substr($relationName, 0, 4) != 'App\\') {
            $relationName = 'App\\'.Str::studly(strtolower(Str::singular($relationName)));
        }
        if (is_subclass_of($relationName, 'Illuminate\Database\Eloquent\Model')) {
            $action .= '(';
            foreach ($pivotIds as $id) {
                $action .= app($relationName)::find($id)->display_name;
                if (next($pivotIds)) {
                    $action .= ', ';
                } else {
                    $action .= ')';
                }
            }
        }
        return $action;
    }

    public static function get_bool($value)
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function concat_action($object, $message = 'editó')
    {
        $old = app(get_class($object));
        $old->fill($object->getOriginal());
        $action = $message;
        $updated_values = $object->getDirty();
        $relationships = $object->relationships();
        foreach ($updated_values as $key => $value) {
            $display_names = ['display_name', 'name', 'code', 'shortened', 'number', 'correlative', 'description'];
            $concat = false;
            $action .= ' [' . Util::translate($key) . '] ';
            if (substr($key, -3, 3) == '_id') {
                $attribute = substr($key, 0, -3);
                if (array_key_exists($attribute, $relationships)) {
                    if ($relationships[$attribute]['type'] == 'BelongsTo') {
                        $old_relation = app($relationships[$attribute]['model'])::find($old[$key]);
                        $new_relation = app($relationships[$attribute]['model'])::find($value);
                        if ($old_relation) {
                            foreach ($display_names as $title) {
                                if (isset($old_relation[$title])) {
                                    $action .= $old_relation[$title];
                                    break;
                                }
                            }
                        }
                        $action .= ' -> ';
                        if ($new_relation) {
                            foreach ($display_names as $title) {
                                if (isset($new_relation[$title])) {
                                    $action .= $new_relation[$title];
                                    break;
                                }
                            }
                        }
                        $concat = true;
                    }
                }
            }
            if (!$concat) {
                $action .= Util::bool_to_string($old[$key]) . ' -> ' . Util::bool_to_string($object[$key]);
            }
            if (next($updated_values)) {
                $action .= ', ';
            }
        }
        return $action;
    }

    public static function save_record($object, $type, $action)
    {
        $record_type = RecordType::whereName($type)->first();
        if ($record_type) {
            $record = $object->records()->make([
                'action' => $action
            ]);
            $record->record_type()->associate($record_type);
            $record->save();
        }
    }

    public static function male_female($gender, $capìtalize = false)
    {
        if ($gender) {
            $ending = strtoupper($gender) == 'M' ? 'o' : 'a';
        } else {
            $ending = strtoupper($gender) == 'M' ? 'el' : 'la';
        }
        if ($capìtalize) $ending = strtoupper($ending);
        return $ending;
    }

    public static function get_civil_status($status, $gender = null)
    {
        $status = self::trim_spaces($status);
        switch ($status) {
            case 'S':
            case 's':
                $status = 'solter';
                break;
            case 'D':
            case 'd':
                $status = 'divorciad';
                break;
            case 'C':
            case 'c':
                $status = 'casad';
                break;
            case 'V':
            case 'v':
                $status = 'viud';
                break;
            default:
                return '';
                break;
        }
        if (is_null($gender) || is_bool($gender) || $gender == '') {
            $status .= 'o(a)';
        } else {
            switch ($gender) {
                case 'M':
                case 'm':
                case 'F':
                case 'f':
                    $status .= self::male_female($gender);
                    break;
                default:
                    return '';
                    break;
            }
        }
        return $status;
    }

    public static function pdf_to_base64($views, $file_name, $size = 'letter', $copies = 1)
    {
        $footerHtml = view()->make('partials.footer')->with(array('paginator' => true, 'print_date' => true, 'date' => Carbon::now()->ISOFormat('L H:m')))->render();
        $options = $size == 'letter' ? [
            'copies' => $copies ?? 1,
            'orientation' => 'portrait',
            'page-width' => '216',
            'page-height' => '279',
            'margin-top' => '8',
            'margin-bottom' => '16',
            'margin-left' => '5',
            'margin-right' => '7',
            'encoding' => 'UTF-8',
            'footer-html' => $footerHtml,
            'user-style-sheet' => public_path('css/report-print.min.css')
        ] : [
            //TODO
        ];
        \PDF::loadHTML($views)->setOptions($options)->save($file_name);
        $content = base64_encode(file_get_contents($file_name));
        File::delete($file_name);
        return [
            'content' => $content,
            'type' => 'pdf',
            'file_name' => $file_name
        ];
    }
}