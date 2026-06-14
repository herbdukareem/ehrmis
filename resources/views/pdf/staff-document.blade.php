<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 12mm; }
        body { margin: 0; font-family: sans-serif; }
        .page { width: 100%; height: 265mm; page-break-after: always; text-align: center; }
        .page:last-child { page-break-after: auto; }
        img { max-width: 100%; max-height: 255mm; }
    </style>
</head>
<body>
    @foreach ($images as $image)
        <div class="page">
            <img src="data:{{ $image['mime_type'] }};base64,{{ $image['data'] }}" alt="">
        </div>
    @endforeach
</body>
</html>
