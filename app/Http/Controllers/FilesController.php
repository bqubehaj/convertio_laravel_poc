<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Convertio\Convertio;
use Convertio\Exceptions\CURLException;
use Mockery\Exception;
use App\File;

class FilesController extends Controller
{
    /**
     * db tuple for file(currently handling)
     */
    private $file;

    /**
     * s3 Object
     */
    private $s3Obj = null;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $file_url = $this->upload_file_to_s3($request->file('testfile'));
        $this->file = new File();
        $this->file->file_url = $file_url;
        $this->file->save();
        $file_id = $this->file->id;

        $this->start_convert($file_url, $file_id);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    /**
     * Store the file to s3 bucket and store the url in database
     * @param $input 
     * @return string $s3_url
     */
    public function upload_file_to_s3($input, $path = "resume")
    {
        $file_name = $this->getFileName($path, $input->getClientOriginalExtension());
        if (! $this->s3Obj) {
            $this->s3Obj = \Storage::disk('s3');
        }
        $file = $this->s3Obj->put($file_name, file_get_contents($input));
        $response = env('S3_HOST') . env('S3_BUCKET') . '/' . $file_name;

        return $response;
    }

    public function getFileName($path = "resume", $extension)
    {
        $file = 'files/' . $path . '/' . time() . '_' . rand(1247, 98112833297341) . '_file.' . $extension;
        if (! $this->s3Obj) {
            $this->s3Obj = \Storage::disk('s3');
        }
        if ($this->s3Obj->has($file)) {
            return $this->getFileName($path, $extension);
        }

        return $file;
    }

    private function start_convert($file_url, $file_id)
    {
        try {
            $api = new Convertio(env('CONVERTIO_KEY'));
            $api->startFromURL(
                $file_url,
                'html',
                [
                    'callback_url' => url('file/converted')
                ]
            );
            // $file = File::find($file_id);
            $this->file->attempt = $this->file->attempt + 1;
            $this->file->convert_id = $api->getConvertID();
            $this->file->convert_status = 'initiated';
            $this->file->save();
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (CURLException $e) {
            echo $e->getMessage();
        }
    }

    /**
     * Callback url for convertio conversion api
     * @param Request $request
     */
    public function converted(Request $request)
    {
        $resp = $request->all();
        \Log::debug($resp);
        try {
            if (File::where('convert_id', $resp['id'])->doesntExist()){
                throw new Exception("File does not exist in the databse");
            }

            $this->file = File::where('convert_id', $resp['id'])->first();

            if ($resp['step'] === "failed") {
                $this->start_convert($this->file->file_url, $this->file->id);
            } else if ($resp['step'] === "finished") {
                try {
                    $up_url = self::getFileName('htmlresume', 'html');
                    $api = new Convertio(env('CONVERTIO_KEY'));
                    $api->__set('convert_id', $this->file->convert_id);
                    $content = $api->fetchResultContent()->result_content;
                    $s3 = \Storage::disk('s3');
                    $s3fileobj = $s3->put($up_url, $content);
                    if ($s3fileobj) {
                        $api->delete();
                        $this->file->convert_status = 'finished';
                        $this->file->html_url = env('S3_HOST') . env('S3_BUCKET') . '/' . $up_url;
                        $this->file->save();
                    }
                } catch (CURLException $e) {
                    echo "CurlException:" + $e->getMessage();
                } catch (Exception $e) {
                    echo $e->getMessage();
                }
            }
        } catch (Exception $e) {
            echo "ArraykeyException:" + $e->getMessage();
        }

    }

}
