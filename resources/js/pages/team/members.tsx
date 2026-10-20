import { Form, Head, router, usePage } from '@inertiajs/react';
import { Mail, Trash2 } from 'lucide-react';
import TeamInvitationController from '@/actions/App/Http/Controllers/Team/TeamInvitationController';
import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/Team/TeamMemberController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/team-members';
import type { TeamRole } from '@/types';
import { edit as editTeam } from '@/routes/team';

type Member = {
    id: number;
    name: string;
    email: string;
    role: TeamRole;
};

type Invitation = {
    id: number;
    email: string;
    role: TeamRole;
    expired: boolean;
    expires_at_diff: string;
};

type Props = {
    members: Member[];
    invitations: Invitation[];
    canManage: boolean;
};

function ConfirmButton({
    label,
    title,
    description,
    onConfirm,
}: {
    label: string;
    title: string;
    description: string;
    onConfirm: () => void;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                >
                    <Trash2 className="h-4 w-4" />
                    <span className="sr-only">{label}</span>
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={onConfirm}>
                        {label}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Members({ members, invitations, canManage }: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Team members" />

            <h1 className="sr-only">Team members</h1>

            {canManage && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Invite a member"
                        description="They get an email link that works for 7 days"
                    />

                    <Form
                        {...TeamInvitationController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="flex flex-wrap items-start gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid min-w-56 flex-1 gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        placeholder="email@example.com"
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid w-32 gap-2">
                                    <Label htmlFor="role">Role</Label>
                                    <Select name="role" defaultValue="member">
                                        <SelectTrigger id="role">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="member">
                                                Member
                                            </SelectItem>
                                            <SelectItem value="admin">
                                                Admin
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.role} />
                                </div>

                                <Button
                                    disabled={processing}
                                    className="mt-[22px]"
                                >
                                    Send invite
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            )}

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Members"
                    description="Owners and admins manage members, social sets and API tokens"
                />

                <div className="overflow-hidden rounded-lg border border-border">
                    {members.map((member) => {
                        const isSelf = member.id === auth.user.id;
                        const editable = canManage && member.role !== 'owner';

                        return (
                            <div
                                key={member.id}
                                className="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                            >
                                <div className="min-w-0 space-y-1">
                                    <p className="truncate font-medium tracking-tight">
                                        {member.name}
                                        {isSelf && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (you)
                                            </span>
                                        )}
                                    </p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {member.email}
                                    </p>
                                </div>

                                <div className="flex shrink-0 items-center gap-2">
                                    {editable ? (
                                        <Select
                                            value={member.role}
                                            onValueChange={(role) =>
                                                router.patch(
                                                    update.url(member.id),
                                                    { role },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-28"
                                                aria-label={`Role for ${member.name}`}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="member">
                                                    Member
                                                </SelectItem>
                                                <SelectItem value="admin">
                                                    Admin
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <Badge
                                            variant="secondary"
                                            className="capitalize"
                                        >
                                            {member.role}
                                        </Badge>
                                    )}

                                    {member.role !== 'owner' &&
                                        (isSelf ? (
                                            <ConfirmButton
                                                label="Leave team"
                                                title="Leave this team?"
                                                description="You lose access to its social sets and posts until someone invites you again."
                                                onConfirm={() =>
                                                    router.delete(
                                                        destroy.url(member.id),
                                                    )
                                                }
                                            />
                                        ) : (
                                            canManage && (
                                                <ConfirmButton
                                                    label="Remove member"
                                                    title={`Remove ${member.name}?`}
                                                    description="They lose access to this team's social sets and posts."
                                                    onConfirm={() =>
                                                        router.delete(
                                                            destroy.url(
                                                                member.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            )
                                        ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {invitations.length > 0 && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Pending invitations"
                        description="Invitations that have not been accepted yet"
                    />

                    <div className="overflow-hidden rounded-lg border border-border">
                        {invitations.map((invitation) => (
                            <div
                                key={invitation.id}
                                className="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                            >
                                <div className="flex min-w-0 items-center gap-4">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                        <Mail className="h-5 w-5 text-muted-foreground" />
                                    </div>
                                    <div className="min-w-0 space-y-1">
                                        <p className="truncate font-medium tracking-tight">
                                            {invitation.email}
                                        </p>
                                        <p className="text-sm text-muted-foreground capitalize">
                                            {invitation.role}
                                            <span className="mx-1 text-muted-foreground/50">
                                                /
                                            </span>
                                            <span className="normal-case">
                                                {invitation.expired
                                                    ? `Expired ${invitation.expires_at_diff}`
                                                    : `Expires ${invitation.expires_at_diff}`}
                                            </span>
                                        </p>
                                    </div>
                                </div>

                                {canManage && (
                                    <ConfirmButton
                                        label="Cancel invitation"
                                        title="Cancel this invitation?"
                                        description={`The link sent to ${invitation.email} will stop working.`}
                                        onConfirm={() =>
                                            router.delete(
                                                TeamInvitationController.destroy.url(
                                                    invitation.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </>
    );
}

Members.layout = {
    breadcrumbs: [
        {
            title: 'Team',
            href: editTeam(),
        },
        {
            title: 'Members',
            href: index(),
        },
    ],
};
