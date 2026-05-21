<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation to {{ $organization->name }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f4f4f4; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
        <h1 style="color: #333; margin-top: 0;">Hello!</h1>
        <p>You have been invited by <strong>{{ $invitedBy->name }}</strong> to join <strong>{{ $organization->name }}</strong> as a <strong>{{ $role->name }}</strong>.</p>
    </div>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $url }}" style="background-color: #007bff; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">
            Accept Invitation
        </a>
    </div>
    
    <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107; margin: 20px 0;">
        <p style="margin: 0;"><strong>Important:</strong> This invitation will expire on {{ $expiresAt->format('F j, Y \a\t g:i A') }}.</p>
    </div>
    
    <p style="color: #666; font-size: 14px; margin-top: 30px;">
        If you did not expect this invitation, you can safely ignore this email.
    </p>
    
    <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
    
    <p style="color: #999; font-size: 12px; text-align: center;">
        This is an automated message. Please do not reply to this email.
    </p>
</body>
</html>
