@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Admin Panel</h1>
        <ul>
            <li><a href="/admin/sms">SMS Bomber Requests</a></li>
            <li><a href="/admin/email">Email Bomber Requests</a></li>
            <li><a href="/admin/logs">Logs</a></li>
            <li><a href="/admin/stats">Statistics</a></li>
        </ul>
    </div>
@endsection