import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { link } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';

type Props = {
    status?: string;
};

export default function ConfirmPassword({ status }: Props) {
    return (
        <>
            <Head title="Confirm it's you" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="Confirm with passkey"
                loadingLabel="Confirming..."
                separator="Or confirm by email"
            />

            <Form {...link.form()}>
                {({ processing }) => (
                    <Button
                        className="w-full"
                        disabled={processing}
                        data-test="confirm-link-button"
                    >
                        {processing && <Spinner />}
                        Email me a confirmation link
                    </Button>
                )}
            </Form>

            {status && (
                <div className="mt-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

ConfirmPassword.layout = {
    title: "Confirm it's you",
    description:
        'This is a secure area of the application. Please confirm before continuing.',
};
