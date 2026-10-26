<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class ContactMessageController extends Controller
{
    /**
     * Admin — list messages with filters.
     */
    public function index(Request $request)
    {
        $query = ContactMessage::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $messages = $query->orderBy('created_at', 'desc')->get();

        return Inertia::render('WebsiteManagement/Messages', [
            'messages' => $messages,
            'filters'  => [
                'search' => $request->search,
                'status' => $request->status ?? 'all',
            ],
            'stats' => [
                'total'   => ContactMessage::count(),
                'unread'  => ContactMessage::where('status', 'new')->count(),
                'read'    => ContactMessage::where('status', 'read')->count(),
                'replied' => ContactMessage::where('status', 'replied')->count(),
            ],
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
        ]);
    }

    /**
     * Admin — mark a message as read.
     */
    public function markRead(ContactMessage $message)
    {
        $message->update(['status' => 'read']);

        return back()->with('success', 'Message marked as read.');
    }

    /**
     * Admin — mark a message as unread.
     */
    public function markUnread(ContactMessage $message)
    {
        $message->update(['status' => 'new']);

        return back()->with('success', 'Marked as unread.');
    }

    /**
     * Admin — reply to a message.
     */
    public function reply(Request $request, ContactMessage $message)
    {
        $validated = $request->validate([
            'reply_message' => 'required|string|max:5000',
        ]);

        $message->update([
            'status'       => 'replied',
            'admin_reply'  => $validated['reply_message'],
            'replied_at'   => now(),
        ]);

        // TODO: Send email to the visitor
        // Mail::raw($validated['reply_message'], function ($mail) use ($message) {
        //     $mail->to($message->email)
        //          ->subject('Re: ' . ($message->subject ?? 'Your message'));
        // });

        Log::info('Reply sent to contact message', [
            'id'    => $message->id,
            'email' => $message->email,
        ]);

        return back()->with('success', 'Reply sent successfully! ✅');
    }

    /**
     * Admin — delete a message.
     */
    public function destroy(ContactMessage $message)
    {
        $message->delete();

        return back()->with('success', 'Message deleted successfully! ✅');
    }

    /**
     * Public — store a new message from the contact form.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:30',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $validated['status'] = 'new';

        ContactMessage::create($validated);

        return back()->with('success', 'Thank you! Your message has been sent successfully. ✅');
    }
}