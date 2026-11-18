import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Chat, type CopilotConversation } from '@/components/copilot/chat';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { show as copilotShow } from '@/routes/copilot';

type ConversationSummary = {
    id: string;
    title: string;
    updated_at: string;
};

type CopilotPageProps = {
    conversations: ConversationSummary[];
    conversation: CopilotConversation | null;
};

export default function Copilot({
    conversations,
    conversation,
}: CopilotPageProps) {
    const [createdConversationId, setCreatedConversationId] = useState<
        string | null
    >(null);
    const [chatSession, setChatSession] = useState(0);

    const activeConversationId = conversation?.id ?? createdConversationId;

    const startNewChat = (): void => {
        setCreatedConversationId(null);
        setChatSession((session) => session + 1);
    };

    const showCreatedConversation = (conversationId: string): void => {
        setCreatedConversationId(conversationId);
        window.history.replaceState(
            window.history.state,
            '',
            copilotShow(conversationId).url,
        );
    };

    return (
        <>
            <Head title="Copilot" />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
                <Heading title="Copilot" variant="small" />

                <div className="flex flex-col gap-6 md:grid md:grid-cols-[16rem_minmax(0,1fr)] md:items-start">
                    <aside className="flex flex-col gap-3">
                        <div className="flex items-center gap-2">
                            <Button asChild className="shrink-0 md:w-full">
                                <Link
                                    href={copilotShow()}
                                    onClick={startNewChat}
                                >
                                    <Plus data-icon="inline-start" />
                                    New chat
                                </Link>
                            </Button>

                            {conversations.length > 0 && (
                                <Select
                                    value={activeConversationId ?? ''}
                                    onValueChange={(conversationId) =>
                                        router.visit(
                                            copilotShow(conversationId),
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        aria-label="Conversations"
                                        className="min-w-0 flex-1 md:hidden"
                                    >
                                        <SelectValue placeholder="New chat" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {conversations.map((item) => (
                                            <SelectItem
                                                key={item.id}
                                                value={item.id}
                                            >
                                                {item.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>

                        <nav
                            aria-label="Conversations"
                            className="hidden flex-col gap-1 md:flex"
                        >
                            {conversations.map((item) => {
                                const isActive =
                                    item.id === activeConversationId;

                                return (
                                    <Link
                                        key={item.id}
                                        href={copilotShow(item.id)}
                                        aria-current={
                                            isActive ? 'page' : undefined
                                        }
                                        className={cn(
                                            'truncate rounded-md px-3 py-2 text-sm',
                                            isActive
                                                ? 'bg-accent font-medium text-accent-foreground'
                                                : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                                        )}
                                    >
                                        {item.title}
                                    </Link>
                                );
                            })}
                        </nav>
                    </aside>

                    <main className="min-w-0">
                        <Chat
                            key={`${chatSession}-${conversation?.id ?? 'new'}`}
                            conversation={conversation}
                            onConversation={showCreatedConversation}
                        />
                    </main>
                </div>
            </div>
        </>
    );
}

Copilot.layout = {
    breadcrumbs: [
        {
            title: 'Copilot',
            href: copilotShow(),
        },
    ],
};
