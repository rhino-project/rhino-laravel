<!DOCTYPE html>
<html>
<head>
    <title>Password Recovery</title>
</head>
<body>
    <h1>Password Recovery</h1>
    <p>Click the link below to reset your password:</p>
    <a href="{{ url('/password/reset', $token) }}">Reset Password</a>
</body>
</html>