import { Form, Head, router } from '@inertiajs/react';
import {
    browserSupportsWebAuthn,
    type PublicKeyCredentialCreationOptionsJSON,
    startRegistration,
} from '@simplewebauthn/browser';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { passkey, passkeyOptions, store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

function xsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    return decodeURIComponent(cookie?.split('=')[1] ?? '');
}

async function postJson<T>(url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(json.message ?? 'Passkey sign-up failed.');
    }

    return json as T;
}

function PasskeySignup() {
    const [isSupported] = useState(
        () => typeof window !== 'undefined' && browserSupportsWebAuthn(),
    );
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!isSupported) {
        return null;
    }

    const signUp = async (form: HTMLFormElement | null) => {
        if (!form) {
            return;
        }

        for (const field of ['name', 'username', 'email']) {
            const input = form.elements.namedItem(field) as HTMLInputElement;

            if (!input.reportValidity()) {
                return;
            }
        }

        const data = new FormData(form);
        const details = {
            name: data.get('name') as string,
            username: data.get('username') as string,
            email: data.get('email') as string,
        };

        setError(null);
        setIsLoading(true);

        try {
            const { options } = await postJson<{
                options: PublicKeyCredentialCreationOptionsJSON;
            }>(passkeyOptions.url(), details);
            const credential = await startRegistration({
                optionsJSON: options,
            });

            await postJson(passkey.url(), { name: details.name, credential });

            router.visit('/dashboard');
        } catch (e) {
            setError(
                e instanceof Error ? e.message : 'Passkey sign-up failed.',
            );
            setIsLoading(false);
        }
    };

    return (
        <>
            <div className="grid gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    disabled={isLoading}
                    onClick={(e) => signUp(e.currentTarget.form)}
                >
                    {isLoading ? <Spinner /> : <KeyRound className="h-4 w-4" />}
                    Sign up with a passkey
                </Button>
                {error && (
                    <InputError message={error} className="text-center" />
                )}
            </div>

            <div className="relative my-2">
                <div className="absolute inset-0 flex items-center">
                    <Separator className="w-full" />
                </div>
                <div className="relative flex justify-center text-xs uppercase">
                    <span className="bg-background px-2 text-muted-foreground">
                        Or create with a password
                    </span>
                </div>
            </div>
        </>
    );
}

export default function Register({ passwordRules }: Props) {
    return (
        <>
            <Head title="Register" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Full name"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    type="text"
                                    required
                                    tabIndex={2}
                                    autoComplete="username"
                                    name="username"
                                    placeholder="username"
                                />
                                <InputError message={errors.username} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <PasskeySignup />

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={5}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Create account
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()} tabIndex={6}>
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Create an account',
    description: 'Enter your details below to create your account',
};
