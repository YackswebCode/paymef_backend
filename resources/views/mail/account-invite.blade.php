<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #111; line-height: 1.5">
    <h2 style="margin-bottom: 4px">Welcome to PayMef</h2>

    <p>Hello {{ $user->name }},</p>

    <p>
        An account has been created for you as a {{ $roleLabel }}. Set your password to activate it.
    </p>

    <p>
        <a href="{{ $url }}"
           style="display: inline-block; padding: 10px 18px; background: #0f766e; color: #fff; text-decoration: none; border-radius: 6px">
            Set my password
        </a>
    </p>

    <p style="color: #555; font-size: 13px">
        This link expires in 60 minutes. If it expires, use "Forgot password" on the sign-in page.
    </p>
</body>
</html>
