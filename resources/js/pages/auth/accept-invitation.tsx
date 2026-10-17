import { Form, Head } from '@inertiajs/react';
import InvitationController from '@/actions/App/Http/Controllers/Auth/InvitationController';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard, home, logout } from '@/routes';

type Props =
    | { status: 'invalid' }
    | {
          status: 'wrong-email' | 'register';
          email: string;
          team: string;
          token: string;
      };

export default function AcceptInvitation(props: Props) {
    if (props.status === 'invalid') {
        return (
            <>
                <Head title="Invitation" />
                <div className="space-y-4 text-center text-sm">
                    <p>
                        This invitation has expired, was already used, or was
                        cancelled. Ask the team for a new one.
                    </p>
                    <TextLink href={home()}>Go to the home page</TextLink>
                </div>
            </>
        );
    }

    if (props.status === 'wrong-email') {
        return (
            <>
                <Head title="Invitation" />
                <div className="space-y-4 text-center text-sm">
                    <p>
                        This invitation to <strong>{props.team}</strong> is for{' '}
                        <strong>{props.email}</strong>, but you are signed in
                        with another email. Log out and open the link again as{' '}
                        {props.email}.
                    </p>
                    <div className="flex justify-center gap-4">
                        <TextLink href={dashboard()}>
                            Back to dashboard
                        </TextLink>
                        <TextLink href={logout()} as="button">
                            Log out
                        </TextLink>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Join team" />
            <p className="mb-6 text-center text-sm text-muted-foreground">
                Create your account to join <strong>{props.team}</strong>.
            </p>
            <Form
                {...InvitationController.store.form(props.token)}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={props.email}
                                readOnly
                                disabled
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                autoComplete="name"
                                placeholder="Full name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                name="username"
                                required
                                autoComplete="username"
                                placeholder="username"
                            />
                            <InputError message={errors.username} />
                        </div>

                        <Button type="submit" className="mt-2 w-full">
                            {processing && <Spinner />}
                            Create account and join
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Team invitation',
    description: 'You have been invited to join a team',
};
